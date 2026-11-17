(() => {
  const { t, setLang, getLang, applyI18n } = window.ChattiesI18n;

  const form = document.getElementById("setup-form");
  const startBtn = document.getElementById("start-btn");
  const stopBtn = document.getElementById("stop-btn");
  const continueBtn = document.getElementById("continue-btn");
  const refreshHistoryBtn = document.getElementById("refresh-history-btn");
  const extendPanel = document.getElementById("extend-panel");
  const extendBtn = document.getElementById("extend-btn");
  const chatEl = document.getElementById("chat");
  const statusEl = document.getElementById("status");
  const metaEl = document.getElementById("meta");
  const historyEl = document.getElementById("history");

  let abort = false;
  let running = false;
  let threadId = null;
  let currentThread = null;
  let turnAbort = null;
  let currentJobId = null;

  function val(id) {
    return document.getElementById(id).value.trim();
  }

  function setVal(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value ?? "";
  }

  function setStatus(text, isError = false) {
    statusEl.textContent = text;
    statusEl.removeAttribute("data-i18n");
    statusEl.classList.toggle("error", isError);
  }

  function syncLangButtons() {
    const current = getLang();
    document.querySelectorAll(".lang-btn").forEach((btn) => {
      btn.setAttribute("aria-pressed", btn.dataset.lang === current ? "true" : "false");
    });
  }

  function refreshDynamicI18n() {
    updateContinueButton(currentThread);
    if (currentThread) {
      renderMeta(currentThread);
      if (canContinue(currentThread) && !running) {
        showResumeHint(currentThread);
      }
      const empty = chatEl.querySelector(".empty");
      if (empty) empty.textContent = t("thread.empty");
    } else {
      const empty = chatEl.querySelector(".empty");
      if (empty) empty.textContent = t("thread.empty");
      if (!running) setStatus(t("status.ready"));
    }
    loadHistory();
  }

  function agentPayload(side) {
    return {
      name: val(`agent-${side}-name`),
      label: val(`agent-${side}-name`),
      apiUrl: val(`agent-${side}-url`),
      model: val(`agent-${side}-model`),
      apiKey: val(`agent-${side}-key`),
      context: val(`agent-${side}-context`),
    };
  }

  function fillFormFromThread(thread) {
    setVal("topic", thread.topic || "");
    setVal("max-rounds", String(thread.maxRounds || 3));
    for (const side of ["a", "b"]) {
      const agent = thread.agents[side] || {};
      setVal(`agent-${side}-name`, agent.name || "");
      setVal(`agent-${side}-url`, agent.apiUrl || "");
      setVal(`agent-${side}-model`, agent.model || "");
      setVal(`agent-${side}-key`, agent.apiKey || "");
      setVal(`agent-${side}-context`, agent.context || "");
    }
  }

  function nextSpeakerInfo(thread) {
    const key = thread.nextSpeaker === "b" ? "b" : "a";
    return { key, name: thread.agents[key].name };
  }

  function canContinue(thread) {
    return thread && (thread.status === "ready" || thread.status === "error" || thread.status === "running");
  }

  function countAgentMessages(thread) {
    return (thread.messages || []).filter((m) => m.speaker === "a" || m.speaker === "b").length;
  }

  function updateContinueButton(thread) {
    const ok = canContinue(thread) && !running;
    continueBtn.hidden = !ok;
    continueBtn.disabled = !ok;
    if (!ok) return;
    continueBtn.textContent = thread.status === "error"
      ? t("thread.continueError")
      : t("thread.continue");
  }

  function hideResumeHint() {
    document.getElementById("resume-hint")?.remove();
  }

  function showResumeHint(thread) {
    hideTyping();
    hideResumeHint();
    if (!canContinue(thread)) return;

    const who = nextSpeakerInfo(thread);
    const round = nextRoundNumber(thread);
    const empty = chatEl.querySelector(".empty");
    if (empty) empty.remove();

    const isError = thread.status === "error";
    const btnLabel = isError ? t("thread.continueError") : t("thread.continue");
    const el = document.createElement("article");
    el.className = "bubble hint";
    el.id = "resume-hint";
    el.innerHTML = `
      <div class="who">
        <span class="who-name">${escapeHtml(t("bubble.hint"))}</span>
        <span class="who-meta">${escapeHtml(t("bubble.round", { round }))}</span>
      </div>
      <div class="body"></div>
      <div class="hint-actions">
        <button type="button" class="primary small" data-resume>${escapeHtml(btnLabel)}</button>
      </div>
    `;
    el.querySelector(".body").textContent = isError
      ? t("resume.error", { name: who.name })
      : t("resume.paused", { name: who.name });
    el.querySelector("[data-resume]").addEventListener("click", () => {
      if (running || !currentThread || !canContinue(currentThread)) return;
      runTurns(currentThread, { force: currentThread.status === "running" });
    });
    chatEl.appendChild(el);
    el.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function rememberThreadId(id) {
    threadId = id;
    setThreadUrl(id);
    try {
      sessionStorage.setItem("chatties.threadId", id);
    } catch {
      // ignore
    }
  }

  function rememberedThreadId() {
    // Only deep-links / history (`?t=`) reopen a thread.
    // Bare `/` (logo / home) must stay a fresh setup — do not revive sessionStorage.
    const params = new URLSearchParams(window.location.search);
    const fromUrl = params.get("t");
    if (fromUrl) return fromUrl;
    try {
      sessionStorage.removeItem("chatties.threadId");
    } catch {
      // ignore
    }
    return null;
  }

  function updateExtendPanel(thread) {
    const show = !!thread && !running && thread.status === "done";
    extendPanel.hidden = !show;
    extendBtn.disabled = !show;
  }

  function renderMeta(thread) {
    currentThread = thread;
    if (!thread) {
      metaEl.innerHTML = "";
      updateContinueButton(null);
      updateExtendPanel(null);
      return;
    }
    const spoken = countAgentMessages(thread);
    const agents = 2;
    const totalSlots = thread.maxRounds * agents;
    const leftRounds = Math.max(0, thread.maxRounds - thread.currentRound);
    metaEl.innerHTML = `
      <span>${escapeHtml(t("meta.round", { current: thread.currentRound || 0, max: thread.maxRounds }))}</span>
      <span>${escapeHtml(t("meta.messages", { spoken, total: totalSlots }))}</span>
      <span>${escapeHtml(t("meta.roundsLeft", { left: leftRounds }))}</span>
      <span>${escapeHtml(t("meta.status", { status: thread.status }))}</span>
      <span>${escapeHtml(t("meta.id", { id: thread.id }))}</span>
    `;
    updateContinueButton(thread);
    updateExtendPanel(thread);
    highlightHistory(thread.id);
  }

  function renderMessages(thread) {
    hideTyping();
    hideResumeHint();
    chatEl.innerHTML = "";
    if (!thread || !thread.messages.length) {
      chatEl.innerHTML = `<p class="empty">${escapeHtml(t("thread.empty"))}</p>`;
      return;
    }
    for (const msg of thread.messages) {
      appendBubble(msg, false);
    }
  }

  function appendBubble(msg, animate = true) {
    const empty = chatEl.querySelector(".empty");
    if (empty) empty.remove();

    const isMod = msg.speaker === "mod" || msg.kind === "directive";
    const el = document.createElement("article");
    el.className = `bubble ${isMod ? "mod" : msg.speaker}`;
    if (!animate) el.style.animation = "none";
    const meta = isMod ? t("bubble.directive") : t("bubble.round", { round: msg.round });
    el.innerHTML = `
      <div class="who">
        <span class="who-name">${escapeHtml(msg.name || (isMod ? "Moderator" : ""))}</span>
        <span class="who-meta">${escapeHtml(meta)}</span>
      </div>
      <div class="body"></div>
    `;
    el.querySelector(".body").textContent = msg.content;
    chatEl.appendChild(el);
    el.scrollIntoView({ behavior: "smooth", block: "nearest" });
    return el;
  }

  function beginStreamBubble(speaker, name, round) {
    hideTyping();
    const empty = chatEl.querySelector(".empty");
    if (empty) empty.remove();

    const el = document.createElement("article");
    el.className = `bubble streaming ${speaker}`;
    el.id = "stream-bubble";
    el.innerHTML = `
      <div class="who">
        <span class="who-name">${escapeHtml(name)}</span>
        <span class="who-meta">${escapeHtml(t("bubble.round", { round }))}</span>
      </div>
      <div class="body">
        <span class="stream-text"></span><span class="typing-dots stream-cursor" aria-hidden="true"><span></span><span></span><span></span></span>
      </div>
    `;
    chatEl.appendChild(el);
    el.scrollIntoView({ behavior: "smooth", block: "nearest" });
    return el.querySelector(".stream-text");
  }

  function endStreamBubble() {
    const el = document.getElementById("stream-bubble");
    if (!el) return;
    const body = el.querySelector(".body");
    const text = el.querySelector(".stream-text")?.textContent ?? body?.textContent ?? "";
    if (body) body.textContent = text;
    el.classList.remove("streaming");
    el.removeAttribute("id");
  }

  function nextRoundNumber(thread) {
    return Math.floor(countAgentMessages(thread) / 2) + 1;
  }

  function showTyping(speaker, name, round) {
    hideTyping();
    const empty = chatEl.querySelector(".empty");
    if (empty) empty.remove();

    const el = document.createElement("article");
    el.className = `bubble typing ${speaker}`;
    el.id = "typing-indicator";
    el.setAttribute("aria-live", "polite");
    el.innerHTML = `
      <div class="who">
        <span class="who-name">${escapeHtml(name)}</span>
        <span class="who-meta">${escapeHtml(t("bubble.round", { round }))}</span>
      </div>
      <div class="body">
        <span class="typing-label">${escapeHtml(t("bubble.thinking"))}</span>
        <span class="typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>
      </div>
    `;
    chatEl.appendChild(el);
    el.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function hideTyping() {
    document.getElementById("typing-indicator")?.remove();
  }

  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function formatWhen(iso) {
    if (!iso) return "—";
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString(getLang() === "de" ? "de-DE" : "en-US", {
      dateStyle: "short",
      timeStyle: "short",
    });
  }

  function truncate(text, n = 90) {
    const t = String(text || "").replace(/\s+/g, " ").trim();
    if (t.length <= n) return t;
    return t.slice(0, n - 1) + "…";
  }

  function highlightHistory(id) {
    historyEl.querySelectorAll(".history-item").forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.id === id);
    });
  }

  function setThreadUrl(id) {
    const url = new URL(window.location.href);
    if (id) url.searchParams.set("t", id);
    else url.searchParams.delete("t");
    history.replaceState({}, "", url);
  }

  async function api(path, options = {}) {
    const res = await fetch(path, {
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      ...options,
    });
    let data = null;
    try {
      data = await res.json();
    } catch {
      throw new Error(t("status.badResponse", { status: res.status }));
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data?.error || `HTTP ${res.status}`);
    }
    return data;
  }

  async function cancelJob(jobId) {
    if (!jobId) return;
    try {
      await api("api/job.php", {
        method: "POST",
        body: JSON.stringify({ id: jobId, action: "cancel" }),
      });
    } catch {
      // ignore — job may already be finished
    }
  }

  async function watchJob(jobId, signal) {
    currentJobId = jobId;
    const res = await fetch(`api/job.php?id=${encodeURIComponent(jobId)}&stream=1`, {
      method: "GET",
      headers: { Accept: "text/event-stream" },
      signal,
    });

    if (!res.ok || !res.body) {
      let msg = `HTTP ${res.status}`;
      try {
        const data = await res.json();
        if (data?.error) msg = data.error;
      } catch {
        // ignore
      }
      throw new Error(msg);
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";
    let bodyEl = null;
    let finalPayload = null;

    const handleEvent = (event, data) => {
      if (event === "start") {
        if (!document.getElementById("stream-bubble")) {
          bodyEl = beginStreamBubble(data.speaker, data.name, data.round);
        } else {
          bodyEl = document.querySelector("#stream-bubble .stream-text");
        }
        setStatus(t("status.writing", { name: data.name }));
        return;
      }
      if (event === "token") {
        if (!bodyEl) {
          bodyEl = beginStreamBubble("a", "…", "?");
        }
        bodyEl.textContent += data.text || "";
        const bubble = document.getElementById("stream-bubble");
        bubble?.scrollIntoView({ behavior: "smooth", block: "nearest" });
        return;
      }
      if (event === "done") {
        if (data.message && bodyEl) {
          bodyEl.textContent = data.message.content;
        }
        endStreamBubble();
        finalPayload = data;
        return;
      }
      if (event === "cancelled") {
        endStreamBubble();
        if (data.thread) {
          currentThread = data.thread;
          renderMeta(data.thread);
        }
        finalPayload = { ...data, cancelled: true, done: false };
        return;
      }
      if (event === "error") {
        endStreamBubble();
        if (data.thread) {
          currentThread = data.thread;
          renderMeta(data.thread);
        }
        throw new Error(data.error || t("status.streamError"));
      }
    };

    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });

        while (true) {
          const sep = buffer.indexOf("\n\n");
          if (sep === -1) break;
          const rawEvent = buffer.slice(0, sep);
          buffer = buffer.slice(sep + 2);

          let eventName = "message";
          const dataLines = [];
          for (const line of rawEvent.split("\n")) {
            if (line.startsWith("event:")) eventName = line.slice(6).trim();
            else if (line.startsWith("data:")) dataLines.push(line.slice(5).trim());
          }
          if (!dataLines.length) continue;
          let payload;
          try {
            payload = JSON.parse(dataLines.join("\n"));
          } catch {
            continue;
          }
          handleEvent(eventName, payload);
        }
      }
    } finally {
      if (currentJobId === jobId) currentJobId = null;
    }

    if (!finalPayload) {
      throw new Error(t("status.streamIncomplete"));
    }
    return finalPayload;
  }

  async function streamTurn(threadIdValue, signal, { force = false } = {}) {
    const started = await api("api/turn.php", {
      method: "POST",
      body: JSON.stringify({ id: threadIdValue, force: !!force }),
    });

    if (started.alreadyDone) {
      return {
        ok: true,
        thread: started.thread,
        message: null,
        done: true,
      };
    }

    return watchJob(started.jobId, signal);
  }

  async function loadHistory() {
    try {
      const data = await api("api/list.php");
      const threads = data.threads || [];
      if (!threads.length) {
        historyEl.innerHTML = `<p class="empty">${escapeHtml(t("history.empty"))}</p>`;
        return;
      }

      historyEl.innerHTML = "";
      for (const item of threads) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "history-item";
        btn.dataset.id = item.id;
        btn.innerHTML = `
          <div class="topic">${escapeHtml(truncate(item.topic) || t("history.noneTopic"))}</div>
          <div class="sub">
            <span class="badge ${escapeHtml(item.status)}">${escapeHtml(item.status)}</span>
            <span>${escapeHtml(item.agents.a)} ↔ ${escapeHtml(item.agents.b)}</span>
            <span>${escapeHtml(t("history.rounds", { current: item.currentRound, max: item.maxRounds }))}</span>
            <span>${escapeHtml(t("history.messages", { count: item.messageCount }))}</span>
            <span>${escapeHtml(formatWhen(item.createdAt))}</span>
          </div>
        `;
        btn.addEventListener("click", () => openThread(item.id));
        historyEl.appendChild(btn);
      }
      if (threadId) highlightHistory(threadId);
    } catch (err) {
      historyEl.innerHTML = `<p class="empty">${escapeHtml(t("history.loadError", { error: err.message || String(err) }))}</p>`;
    }
  }

  async function fetchThread(id) {
    const data = await api(`api/thread.php?id=${encodeURIComponent(id)}`);
    return { thread: data.thread, job: data.job || null };
  }

  async function openThread(id, { autoContinue = false } = {}) {
    if (running) {
      setStatus(t("status.waitRunning"), true);
      return;
    }

    setStatus(t("status.loadingThread"));
    try {
      const loaded = await fetchThread(id);
      let thread = loaded.thread;
      const activeJobId = thread.activeJobId || loaded.job?.id || null;
      const jobActive =
        !!activeJobId &&
        thread.status === "running" &&
        loaded.job &&
        (loaded.job.status === "queued" || loaded.job.status === "running");

      currentThread = thread;
      rememberThreadId(thread.id);
      fillFormFromThread(thread);
      renderMessages(thread);
      renderMeta(thread);
      highlightHistory(thread.id);
      document.getElementById("chat").scrollIntoView({ behavior: "smooth", block: "start" });

      if (jobActive) {
        setStatus(t("status.reconnecting", { name: loaded.job.name || nextSpeakerInfo(thread).name }));
        await runTurns(thread, { resumeJobId: activeJobId });
        return;
      }

      if (thread.status === "done") {
        hideTyping();
        hideResumeHint();
        setStatus(t("status.archived"));
      } else if (thread.status === "error") {
        showResumeHint(thread);
        setStatus(thread.error || t("status.errorResume"), true);
      } else if (canContinue(thread)) {
        showResumeHint(thread);
        const who = nextSpeakerInfo(thread);
        setStatus(
          thread.resumedFromRunning
            ? t("status.interrupted", { name: who.name })
            : t("status.paused", { name: who.name })
        );
      } else {
        hideTyping();
        hideResumeHint();
        setStatus(t("status.threadLoaded"));
      }

      if (autoContinue && canContinue(thread)) {
        await runTurns(thread, { force: !!thread.resumedFromRunning });
      }
    } catch (err) {
      setStatus(err.message || String(err), true);
    }
  }

  async function runTurns(thread, { force = false, resumeJobId = null } = {}) {
    abort = false;
    running = true;
    startBtn.disabled = true;
    stopBtn.disabled = false;
    updateContinueButton(null);
    updateExtendPanel(null);
    hideTyping();
    hideResumeHint();

    try {
      rememberThreadId(thread.id);
      setStatus(t("status.running"));

      while (!abort) {
        const who = nextSpeakerInfo(thread);
        const round = nextRoundNumber(thread);
        const resuming = !!resumeJobId;
        showTyping(who.key, who.name, round);
        setStatus(
          resuming
            ? t("status.reconnecting", { name: who.name })
            : t("status.thinking", { name: who.name, round })
        );

        turnAbort = new AbortController();
        let turn;
        try {
          if (resumeJobId) {
            const jobId = resumeJobId;
            resumeJobId = null;
            turn = await watchJob(jobId, turnAbort.signal);
          } else {
            turn = await streamTurn(threadId, turnAbort.signal, { force });
            force = false;
          }
        } catch (err) {
          if (err.name === "AbortError" || abort) {
            setStatus(t("status.stopped"));
            break;
          }
          throw err;
        } finally {
          turnAbort = null;
          hideTyping();
          endStreamBubble();
        }

        if (turn.cancelled || abort) {
          if (turn.thread) {
            thread = turn.thread;
            currentThread = thread;
            renderMeta(thread);
            showResumeHint(thread);
          }
          setStatus(t("status.stopped"));
          break;
        }

        thread = turn.thread;
        currentThread = thread;
        renderMeta(thread);

        if (turn.done || thread.status === "done") {
          setStatus(t("status.done"));
          break;
        }
        if (thread.status === "error") {
          setStatus(thread.error || t("status.error"), true);
          break;
        }

        const next = nextSpeakerInfo(thread);
        setStatus(
          t("status.nextUp", {
            current: thread.currentRound,
            max: thread.maxRounds,
            name: next.name,
          })
        );
      }
    } catch (err) {
      hideTyping();
      endStreamBubble();
      setStatus(err.message || String(err), true);
    } finally {
      hideTyping();
      endStreamBubble();
      running = false;
      currentJobId = null;
      startBtn.disabled = false;
      stopBtn.disabled = true;
      updateContinueButton(currentThread);
      updateExtendPanel(currentThread);
      await loadHistory();
    }
  }

  async function runConversation() {
    setStatus(t("status.creating"));
    try {
      const start = await api("api/start.php", {
        method: "POST",
        body: JSON.stringify({
          topic: val("topic"),
          maxRounds: Number(val("max-rounds") || 3),
          agentA: agentPayload("a"),
          agentB: agentPayload("b"),
        }),
      });

      rememberThreadId(start.thread.id);
      renderMessages(start.thread);
      renderMeta(start.thread);
      await loadHistory();
      await runTurns(start.thread);
    } catch (err) {
      setStatus(err.message || String(err), true);
    }
  }

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    if (running) return;
    runConversation();
  });

  stopBtn.addEventListener("click", () => {
    abort = true;
    setStatus(t("status.stopping"));
    const jobId = currentJobId;
    cancelJob(jobId).finally(() => {
      turnAbort?.abort();
    });
  });

  continueBtn.addEventListener("click", () => {
    if (running || !currentThread || !canContinue(currentThread)) return;
    // Force only if a stale lock remains; normally Continue starts the next job.
    const force = currentThread.status === "running";
    runTurns(currentThread, { force });
  });

  extendBtn.addEventListener("click", async () => {
    if (running || !currentThread) return;
    const extraRounds = Number(val("extend-rounds") || 1);
    const directive = val("extend-directive");
    if (!Number.isFinite(extraRounds) || extraRounds < 1) {
      setStatus(t("status.extendNeedRounds"), true);
      return;
    }

    extendBtn.disabled = true;
    setStatus(t("status.extending"));
    try {
      const data = await api("api/extend.php", {
        method: "POST",
        body: JSON.stringify({
          id: currentThread.id,
          extraRounds,
          directive,
        }),
      });

      setVal("extend-directive", "");
      renderMessages(data.thread);
      renderMeta(data.thread);
      await loadHistory();
      setStatus(
        directive
          ? t("status.extendOkDirective", { n: extraRounds })
          : t("status.extendOk", { n: extraRounds })
      );
      await runTurns(data.thread);
    } catch (err) {
      setStatus(err.message || String(err), true);
      updateExtendPanel(currentThread);
    }
  });

  refreshHistoryBtn.addEventListener("click", () => loadHistory());

  document.querySelectorAll(".lang-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      setLang(btn.dataset.lang);
      syncLangButtons();
      refreshDynamicI18n();
    });
  });

  document.addEventListener("chatties:lang", () => {
    syncLangButtons();
  });

  stopBtn.disabled = true;
  continueBtn.hidden = true;
  extendPanel.hidden = true;

  applyI18n();
  syncLangButtons();

  loadHistory().then(() => {
    const id = rememberedThreadId();
    if (id) openThread(id);
  });
})();
