<?php
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>chatties | Zwei LLMs im Dialog</title>
  <style><?php readfile(__DIR__ . '/assets/app.css'); ?></style>
</head>
<body>
  <div class="shell">
    <header class="top">
      <div>
        <h1 class="brand"><a href="./">chatties</a></h1>
        <p class="tagline">Zwei lokale LLMs (LM Studio) diskutieren ein Thema, abwechselnd, mit begrenzten Runden.</p>
      </div>
    </header>

    <section class="panel">
      <h2>Setup</h2>
      <form id="setup-form" class="setup" method="post" action="#" onsubmit="return false;">
        <div class="field">
          <label for="topic">Gemeinsames Thema / Fragestellung</label>
          <textarea id="topic" required placeholder="z.B. Erklärt euch gegenseitig Quicksort vs. Mergesort — und einigt euch, wann welcher Ansatz besser passt."></textarea>
        </div>

        <div class="row">
          <div class="field">
            <label for="max-rounds">Max. Runden (pro Runde sprechen alle einmal)</label>
            <input id="max-rounds" type="number" min="1" max="40" value="3">
          </div>
        </div>

        <div class="agents">
          <div class="agent a">
            <div class="agent-head"><span class="dot"></span> Agent A</div>
            <div class="field">
              <label for="agent-a-name">Name</label>
              <input id="agent-a-name" value="Lehrer" required>
            </div>
            <div class="field">
              <label for="agent-a-url">API-URL (LM Studio)</label>
              <input id="agent-a-url" value="http://127.0.0.1:1234/v1" required>
            </div>
            <div class="field">
              <label for="agent-a-model">Model-ID (optional, sonst was geladen ist)</label>
              <input id="agent-a-model" placeholder="z.B. qwen2.5-7b-instruct">
            </div>
            <div class="field">
              <label for="agent-a-context">Rollen-Kontext (nur für A)</label>
              <textarea id="agent-a-context" placeholder="Du bist ein geduldiger Lehrer. Du stellst gezielte Fragen und korrigierst behutsam."></textarea>
            </div>
          </div>

          <div class="agent b">
            <div class="agent-head"><span class="dot"></span> Agent B</div>
            <div class="field">
              <label for="agent-b-name">Name</label>
              <input id="agent-b-name" value="Schüler" required>
            </div>
            <div class="field">
              <label for="agent-b-url">API-URL (LM Studio)</label>
              <input id="agent-b-url" value="http://127.0.0.1:1234/v1" required>
            </div>
            <div class="field">
              <label for="agent-b-model">Model-ID (optional)</label>
              <input id="agent-b-model" placeholder="z.B. anderes Modell / anderer Port">
            </div>
            <div class="field">
              <label for="agent-b-context">Rollen-Kontext (nur für B)</label>
              <textarea id="agent-b-context" placeholder="Du bist ein neugieriger Schüler. Du fragst nach, wenn etwas unklar ist, und denkst laut."></textarea>
            </div>
          </div>
        </div>

        <div class="actions">
          <button type="submit" class="primary" id="start-btn">Konversation starten</button>
          <button type="button" class="ghost" id="stop-btn">Stoppen</button>
          <span class="status" id="status">Bereit.</span>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Thread</h2>
      <div class="meta" id="meta"></div>
      <div class="chat" id="chat">
        <p class="empty">Noch keine Beiträge. Starte eine Konversation.</p>
      </div>
    </section>
  </div>

  <script><?php readfile(__DIR__ . '/assets/app.js'); ?></script>
</body>
</html>
