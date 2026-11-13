(() => {
  const form = document.getElementById("setup-form");
  const startBtn = document.getElementById("start-btn");
  const stopBtn = document.getElementById("stop-btn");
  const continueBtn = document.getElementById("continue-btn");
  const refreshHistoryBtn = document.getElementById("refresh-history-btn");
  const chatEl = document.getElementById("chat");
  const statusEl = document.getElementById("status");
  const metaEl = document.getElementById("meta");
  const historyEl = document.getElementById("history");

  let abort = false;
  let running = false;
  let threadId = null;
  let currentThread = null;
  let turnAbort = null;

  function val(id) {
    return document.getElementById(id).value.trim();
  }

  function setVal(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value ?? "";
  }

  function setStatus(text, isError = false) {
    statusEl.textContent = text;
    statusEl.classList.toggle("error", isError);
  }

  function agentPayload(side) {
    return {
      name: val(`agent-${side}-name`),
      label: val(`agent-${side}-name`),
      apiUrl: val(`agent-${side}-url`),
      model: val(`agent-${side}-model`),
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

  function updateContinueButton(thread) {
    const ok = canContinue(thread) && !running;
    continueBtn.hidden = !ok;
    continueBtn.disabled = !ok;
  }

  function renderMeta(thread) {
    currentThread = thread;
    if (!thread) {
      metaEl.innerHTML = "";
      updateContinueButton(null);
      return;
    }
    const spoken = thread.messages.length;
    const agents = 2;
    const totalSlots = thread.maxRounds * agents;
    const leftRounds = Math.max(0, thread.maxRounds - thread.currentRound);
    metaEl.innerHTML = `
      <span>Runde ${thread.currentRound || 0}/${thread.maxRounds}</span>
      <span>Beiträge: ${spoken}/${totalSlots}</span>
      <span>Runden Rest: ${leftRounds}</span>
      <span>Status: ${thread.status}</span>
      <span>ID: ${thread.id}</span>
    `;
    updateContinueButton(thread);
    highlightHistory(thread.id);
  }

  function renderMessages(thread) {
    hideTyping();
    chatEl.innerHTML = "";
    if (!thread || !thread.messages.length) {
      chatEl.innerHTML = `<p class="empty">Noch keine Beiträge. Starte eine Konversation.</p>`;
      return;
    }
    for (const msg of thread.messages) {
      appendBubble(msg, false);
    }
  }

  function appendBubble(msg, animate = true) {
    const empty = chatEl.querySelector(".empty");
    if (empty) empty.remove();

    const el = document.createElement("article");
    el.className = `bubble ${msg.speaker}`;
    if (!animate) el.style.animation = "none";
    el.innerHTML = `
      <div class="who">
        <span class="who-name">${escapeHtml(msg.name)}</span>
        <span class="who-meta">Runde ${msg.round}</span>
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
        <span class="who-meta">Runde ${round}</span>
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
    return Math.floor((thread.messages?.length || 0) / 2) + 1;
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
        <span class="who-meta">Runde ${round}</span>
      </div>
      <div class="body">
        <span class="typing-label">denkt nach</span>
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
    return d.toLocaleString("de-DE", {
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
      throw new Error(`Ungültige Antwort (${res.status})`);
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data?.error || `HTTP ${res.status}`);
    }
    return data;
  }

  async function streamTurn(threadIdValue, signal) {
    const res = await fetch("api/turn.php", {
      method: "POST",
      headers: {
        Accept: "text/event-stream",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ id: threadIdValue }),
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
        bodyEl = beginStreamBubble(data.speaker, data.name, data.round);
        setStatus(`${data.name} schreibt…`);
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
      if (event === "error") {
        endStreamBubble();
        if (data.thread) {
          currentThread = data.thread;
          renderMeta(data.thread);
        }
        throw new Error(data.error || "Stream-Fehler");
      }
    };

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

    if (!finalPayload) {
      throw new Error("Stream endete ohne Abschluss.");
    }
    return finalPayload;
  }

  async function loadHistory() {
    try {
      const data = await api("api/list.php");
      const threads = data.threads || [];
      if (!threads.length) {
        historyEl.innerHTML = `<p class="empty">Noch keine Threads gespeichert.</p>`;
        return;
      }

      historyEl.innerHTML = "";
      for (const t of threads) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "history-item";
        btn.dataset.id = t.id;
        btn.innerHTML = `
          <div class="topic">${escapeHtml(truncate(t.topic) || "(ohne Thema)")}</div>
          <div class="sub">
            <span class="badge ${escapeHtml(t.status)}">${escapeHtml(t.status)}</span>
            <span>${escapeHtml(t.agents.a)} ↔ ${escapeHtml(t.agents.b)}</span>
            <span>${t.currentRound}/${t.maxRounds} Runden</span>
            <span>${t.messageCount} Beiträge</span>
            <span>${escapeHtml(formatWhen(t.createdAt))}</span>
          </div>
        `;
        btn.addEventListener("click", () => openThread(t.id));
        historyEl.appendChild(btn);
      }
      if (threadId) highlightHistory(threadId);
    } catch (err) {
      historyEl.innerHTML = `<p class="empty">History konnte nicht geladen werden: ${escapeHtml(err.message || String(err))}</p>`;
    }
  }

  async function openThread(id, { autoContinue = false } = {}) {
    if (running) {
      setStatus("Bitte warte, bis der laufende Dialog stoppt.", true);
      return;
    }

    setStatus("Thread wird geladen…");
    try {
      const data = await api(`api/thread.php?id=${encodeURIComponent(id)}`);
      let thread = data.thread;

      if (thread.status === "running") {
        thread = { ...thread, status: "ready", error: null };
      }

      threadId = thread.id;
      currentThread = thread;
      setThreadUrl(thread.id);
      fillFormFromThread(thread);
      renderMessages(thread);
      renderMeta(thread);

      if (thread.status === "done") {
        setStatus("Archiviert (fertig).");
      } else if (thread.status === "error") {
        setStatus(thread.error || "Fehlerhaft — Fortsetzen möglich.", true);
      } else {
        setStatus("Thread geladen.");
      }

      highlightHistory(thread.id);
      document.getElementById("chat").scrollIntoView({ behavior: "smooth", block: "start" });

      if (autoContinue && canContinue(thread)) {
        await runTurns(thread);
      }
    } catch (err) {
      setStatus(err.message || String(err), true);
    }
  }

  async function runTurns(thread) {
    abort = false;
    running = true;
    startBtn.disabled = true;
    stopBtn.disabled = false;
    updateContinueButton(null);
    hideTyping();

    try {
      threadId = thread.id;
      setThreadUrl(threadId);
      setStatus("Konversation läuft…");

      while (!abort) {
        const who = nextSpeakerInfo(thread);
        const round = nextRoundNumber(thread);
        showTyping(who.key, who.name, round);
        setStatus(`${who.name} denkt nach (Runde ${round})…`);

        turnAbort = new AbortController();
        let turn;
        try {
          turn = await streamTurn(threadId, turnAbort.signal);
        } catch (err) {
          if (err.name === "AbortError" || abort) {
            setStatus("Gestoppt.");
            break;
          }
          throw err;
        } finally {
          turnAbort = null;
          hideTyping();
          endStreamBubble();
        }

        thread = turn.thread;
        currentThread = thread;
        renderMeta(thread);

        if (turn.done || thread.status === "done") {
          setStatus("Fertig.");
          break;
        }
        if (thread.status === "error") {
          setStatus(thread.error || "Fehler", true);
          break;
        }

        const next = nextSpeakerInfo(thread);
        setStatus(
          `Runde ${thread.currentRound}/${thread.maxRounds} — ${next.name} ist dran…`
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
      startBtn.disabled = false;
      stopBtn.disabled = true;
      updateContinueButton(currentThread);
      await loadHistory();
    }
  }

  async function runConversation() {
    setStatus("Thread wird angelegt…");
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
    setStatus("Stoppe…");
    turnAbort?.abort();
  });

  continueBtn.addEventListener("click", () => {
    if (running || !currentThread || !canContinue(currentThread)) return;
    runTurns(currentThread);
  });

  refreshHistoryBtn.addEventListener("click", () => loadHistory());

  stopBtn.disabled = true;
  continueBtn.hidden = true;

  loadHistory().then(() => {
    const params = new URLSearchParams(window.location.search);
    const id = params.get("t");
    if (id) openThread(id);
  });
})();
