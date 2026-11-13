(() => {
  const form = document.getElementById("setup-form");
  const startBtn = document.getElementById("start-btn");
  const stopBtn = document.getElementById("stop-btn");
  const chatEl = document.getElementById("chat");
  const statusEl = document.getElementById("status");
  const metaEl = document.getElementById("meta");

  let abort = false;
  let running = false;
  let threadId = null;

  function val(id) {
    return document.getElementById(id).value.trim();
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

  function nextSpeakerInfo(thread) {
    const key = thread.nextSpeaker === "b" ? "b" : "a";
    return { key, name: thread.agents[key].name };
  }

  function renderMeta(thread) {
    if (!thread) {
      metaEl.innerHTML = "";
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
      <div class="who">${escapeHtml(msg.name)} · Runde ${msg.round}</div>
      <div class="body"></div>
    `;
    el.querySelector(".body").textContent = msg.content;
    chatEl.appendChild(el);
    el.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function showTyping(speaker, name) {
    hideTyping();
    const empty = chatEl.querySelector(".empty");
    if (empty) empty.remove();

    const el = document.createElement("article");
    el.className = `bubble typing ${speaker}`;
    el.id = "typing-indicator";
    el.setAttribute("aria-live", "polite");
    el.innerHTML = `
      <div class="who">${escapeHtml(name)} tippt</div>
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

  async function runConversation() {
    abort = false;
    running = true;
    startBtn.disabled = true;
    stopBtn.disabled = false;
    hideTyping();
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

      threadId = start.thread.id;
      let thread = start.thread;
      renderMessages(thread);
      renderMeta(thread);
      setStatus("Konversation läuft…");

      while (!abort) {
        const who = nextSpeakerInfo(thread);
        showTyping(who.key, who.name);
        setStatus(`${who.name} tippt…`);

        const turn = await api("api/turn.php", {
          method: "POST",
          body: JSON.stringify({ id: threadId }),
        });

        hideTyping();
        thread = turn.thread;

        if (turn.message) {
          appendBubble(turn.message, true);
        }
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

      if (abort) {
        setStatus("Gestoppt.");
      }
    } catch (err) {
      hideTyping();
      setStatus(err.message || String(err), true);
    } finally {
      hideTyping();
      running = false;
      startBtn.disabled = false;
      stopBtn.disabled = true;
    }
  }

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    if (running) return;
    runConversation();
  });

  stopBtn.addEventListener("click", () => {
    abort = true;
    setStatus("Stoppe nach aktuellem Turn…");
  });

  stopBtn.disabled = true;
})();
