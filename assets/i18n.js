(() => {
  const STORAGE_KEY = "chatties.lang";

  const dict = {
    en: {
      "doc.title": "chatties | Two LLMs in dialogue",
      "header.tagline":
        "Two LLMs discuss a topic in turns — via OpenAI-compatible API URLs, with a limited number of rounds.",
      "lang.label": "Language",
      "setup.title": "Setup",
      "setup.topic": "Shared topic / question",
      "setup.topic.placeholder":
        "e.g. Explain Quicksort vs. Mergesort to each other — and agree when each approach fits better.",
      "setup.maxRounds": "Max. rounds (each round: every agent speaks once)",
      "agent.name": "Name",
      "agent.url": "API URL",
      "agent.model": "Model ID (optional)",
      "agent.key": "API key (optional)",
      "agent.key.placeholder": "Bearer token if required",
      "agent.model.placeholderA": "e.g. qwen2.5-7b-instruct",
      "agent.model.placeholderB": "e.g. another model / port",
      "agent.contextA": "Role context (A only)",
      "agent.contextB": "Role context (B only)",
      "agent.contextA.placeholder":
        "You are a patient teacher. You ask focused questions and correct gently.",
      "agent.contextB.placeholder":
        "You are a curious student. You ask when something is unclear and think out loud.",
      "actions.start": "Start conversation",
      "actions.stop": "Stop",
      "status.ready": "Ready.",
      "thread.title": "Thread",
      "thread.continue": "Continue",
      "thread.continueError": "Continue after error",
      "thread.empty": "No messages yet. Start a conversation.",
      "extend.title": "Continue further",
      "extend.hint": "Grant more rounds and optionally steer the direction.",
      "extend.directive": "Nudge (optional)",
      "extend.directive.placeholder":
        "e.g. Please get concrete with an example and agree on a recommendation.",
      "extend.rounds": "Extra rounds",
      "extend.submit": "Grant rounds & continue",
      "history.title": "Past threads",
      "history.refresh": "Refresh",
      "history.empty": "No threads saved yet.",
      "history.noneTopic": "(no topic)",
      "history.rounds": "{current}/{max} rounds",
      "history.messages": "{count} messages",
      "history.loadError": "Could not load history: {error}",
      "meta.round": "Round {current}/{max}",
      "meta.messages": "Messages: {spoken}/{total}",
      "meta.roundsLeft": "Rounds left: {left}",
      "meta.status": "Status: {status}",
      "meta.id": "ID: {id}",
      "bubble.round": "Round {round}",
      "bubble.directive": "Nudge",
      "bubble.hint": "Notice",
      "bubble.thinking": "thinking",
      "resume.error":
        "The conversation stopped with an error. Next up would be {name}.",
      "resume.paused":
        "The conversation is paused. Next up would be {name}.",
      "status.waitRunning": "Please wait until the current dialogue stops.",
      "status.loadingThread": "Loading thread…",
      "status.waitingInFlight": "A generation may still be running on the server — waiting…",
      "status.reconnecting": "Reconnecting to {name}’s generation…",
      "status.archived": "Archived (done).",
      "status.errorResume": "Errored — continue below.",
      "status.interrupted": "Previous job expired — {name} is next.",
      "status.paused": "Paused — next is {name}.",
      "status.threadLoaded": "Thread loaded.",
      "status.creating": "Creating thread…",
      "status.running": "Conversation running…",
      "status.thinking": "{name} is thinking (round {round})…",
      "status.writing": "{name} is writing…",
      "status.stopped": "Stopped.",
      "status.done": "Done.",
      "status.error": "Error",
      "status.nextUp": "Round {current}/{max} — {name} is next…",
      "status.stopping": "Stopping…",
      "leave.warn":
        "A turn is generating in the background. You can reload and reconnect; Stop cancels the model request.",
      "status.extending": "Granting rounds…",
      "status.extendNeedRounds": "Please enter at least 1 extra round.",
      "status.extendOkDirective":
        "{n} round(s) + nudge — continuing.",
      "status.extendOk": "{n} round(s) granted — continuing.",
      "status.streamError": "Stream error",
      "status.streamIncomplete": "Stream ended without completion.",
      "status.badResponse": "Invalid response ({status})",
    },
    de: {
      "doc.title": "chatties | Zwei LLMs im Dialog",
      "header.tagline":
        "Zwei LLMs diskutieren ein Thema abwechselnd — über OpenAI-kompatible API-URLs, mit begrenzten Runden.",
      "lang.label": "Sprache",
      "setup.title": "Setup",
      "setup.topic": "Gemeinsames Thema / Fragestellung",
      "setup.topic.placeholder":
        "z.B. Erklärt euch gegenseitig Quicksort vs. Mergesort — und einigt euch, wann welcher Ansatz besser passt.",
      "setup.maxRounds": "Max. Runden (pro Runde sprechen alle einmal)",
      "agent.name": "Name",
      "agent.url": "API-URL",
      "agent.model": "Model-ID (optional)",
      "agent.key": "API-Key (optional)",
      "agent.key.placeholder": "Bearer-Token, falls nötig",
      "agent.model.placeholderA": "z.B. qwen2.5-7b-instruct",
      "agent.model.placeholderB": "z.B. anderes Modell / anderer Port",
      "agent.contextA": "Rollen-Kontext (nur für A)",
      "agent.contextB": "Rollen-Kontext (nur für B)",
      "agent.contextA.placeholder":
        "Du bist ein geduldiger Lehrer. Du stellst gezielte Fragen und korrigierst behutsam.",
      "agent.contextB.placeholder":
        "Du bist ein neugieriger Schüler. Du fragst nach, wenn etwas unklar ist, und denkst laut.",
      "actions.start": "Konversation starten",
      "actions.stop": "Stoppen",
      "status.ready": "Bereit.",
      "thread.title": "Thread",
      "thread.continue": "Fortsetzen",
      "thread.continueError": "Nach Fehler fortsetzen",
      "thread.empty": "Noch keine Beiträge. Starte eine Konversation.",
      "extend.title": "Weiterführen",
      "extend.hint": "Mehr Runden freigeben und optional die Richtung korrigieren.",
      "extend.directive": "Impuls (optional)",
      "extend.directive.placeholder":
        "z.B. Bitte konkretisiert jetzt mit einem Beispiel und einigt euch auf eine Empfehlung.",
      "extend.rounds": "Zusätzliche Runden",
      "extend.submit": "Runden gewähren & weiter",
      "history.title": "Vergangene Threads",
      "history.refresh": "Aktualisieren",
      "history.empty": "Noch keine Threads gespeichert.",
      "history.noneTopic": "(ohne Thema)",
      "history.rounds": "{current}/{max} Runden",
      "history.messages": "{count} Beiträge",
      "history.loadError": "History konnte nicht geladen werden: {error}",
      "meta.round": "Runde {current}/{max}",
      "meta.messages": "Beiträge: {spoken}/{total}",
      "meta.roundsLeft": "Runden Rest: {left}",
      "meta.status": "Status: {status}",
      "meta.id": "ID: {id}",
      "bubble.round": "Runde {round}",
      "bubble.directive": "Impuls",
      "bubble.hint": "Hinweis",
      "bubble.thinking": "denkt nach",
      "resume.error":
        "Die Konversation ist mit einem Fehler gestoppt. Als Nächstes wäre {name} dran.",
      "resume.paused":
        "Die Konversation ist pausiert. Als Nächstes wäre {name} dran.",
      "status.waitRunning": "Bitte warte, bis der laufende Dialog stoppt.",
      "status.loadingThread": "Thread wird geladen…",
      "status.waitingInFlight": "Auf dem Server läuft ggf. noch eine Generierung — warte kurz…",
      "status.reconnecting": "Verbinde erneut mit der Generierung von {name}…",
      "status.archived": "Archiviert (fertig).",
      "status.errorResume": "Fehlerhaft — unten fortsetzen.",
      "status.interrupted": "Vorheriger Job abgelaufen — als Nächstes {name}.",
      "status.paused": "Pausiert — als Nächstes {name}.",
      "status.threadLoaded": "Thread geladen.",
      "status.creating": "Thread wird angelegt…",
      "status.running": "Konversation läuft…",
      "status.thinking": "{name} denkt nach (Runde {round})…",
      "status.writing": "{name} schreibt…",
      "status.stopped": "Gestoppt.",
      "status.done": "Fertig.",
      "status.error": "Fehler",
      "status.nextUp": "Runde {current}/{max} — {name} ist dran…",
      "status.stopping": "Stoppe…",
      "leave.warn":
        "Ein Turn läuft im Hintergrund weiter. Neuladen ist ok — du verbindest dich wieder; Stoppen bricht die Modell-Anfrage ab.",
      "status.extending": "Runden werden gewährt…",
      "status.extendNeedRounds": "Bitte mindestens 1 zusätzliche Runde angeben.",
      "status.extendOkDirective":
        "{n} Runde(n) + Impuls — weiter geht’s.",
      "status.extendOk": "{n} Runde(n) gewährt — weiter geht’s.",
      "status.streamError": "Stream-Fehler",
      "status.streamIncomplete": "Stream endete ohne Abschluss.",
      "status.badResponse": "Ungültige Antwort ({status})",
    },
  };

  let lang = "en";

  function detectLang() {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved === "de" || saved === "en") return saved;
    } catch {
      // ignore
    }
    const nav = (navigator.language || "en").toLowerCase();
    return nav.startsWith("de") ? "de" : "en";
  }

  function t(key, vars = {}) {
    const table = dict[lang] || dict.en;
    let text = table[key] ?? dict.en[key] ?? key;
    for (const [k, v] of Object.entries(vars)) {
      text = text.replaceAll(`{${k}}`, String(v));
    }
    return text;
  }

  function applyI18n(root = document) {
    root.querySelectorAll("[data-i18n]").forEach((el) => {
      const key = el.getAttribute("data-i18n");
      if (!key) return;
      el.textContent = t(key);
    });
    root.querySelectorAll("[data-i18n-html]").forEach((el) => {
      const key = el.getAttribute("data-i18n-html");
      if (!key) return;
      el.innerHTML = t(key);
    });
    root.querySelectorAll("[data-i18n-placeholder]").forEach((el) => {
      const key = el.getAttribute("data-i18n-placeholder");
      if (!key) return;
      el.setAttribute("placeholder", t(key));
    });
    root.querySelectorAll("[data-i18n-title]").forEach((el) => {
      const key = el.getAttribute("data-i18n-title");
      if (!key) return;
      el.setAttribute("title", t(key));
    });
    document.title = t("doc.title");
    document.documentElement.lang = lang;
  }

  function setLang(next) {
    lang = next === "de" ? "de" : "en";
    try {
      localStorage.setItem(STORAGE_KEY, lang);
    } catch {
      // ignore
    }
    applyI18n();
    document.dispatchEvent(new CustomEvent("chatties:lang", { detail: { lang } }));
  }

  function getLang() {
    return lang;
  }

  lang = detectLang();
  window.ChattiesI18n = { t, setLang, getLang, applyI18n, detectLang };
})();
