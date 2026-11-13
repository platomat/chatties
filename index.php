<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Env.php';
Env::load();

$defaultApiUrl = Env::get('CHATTIES_API_URL', 'http://127.0.0.1:1234/v1') ?? 'http://127.0.0.1:1234/v1';
$defaultModel = Env::get('CHATTIES_MODEL', '') ?? '';
$defaultApiKey = Env::get('CHATTIES_API_KEY', '') ?? '';
$apiUrlA = Env::get('CHATTIES_API_URL_A', $defaultApiUrl) ?? $defaultApiUrl;
$apiUrlB = Env::get('CHATTIES_API_URL_B', $defaultApiUrl) ?? $defaultApiUrl;
$modelA = Env::get('CHATTIES_MODEL_A', $defaultModel) ?? $defaultModel;
$modelB = Env::get('CHATTIES_MODEL_B', $defaultModel) ?? $defaultModel;
$apiKeyA = Env::get('CHATTIES_API_KEY_A', $defaultApiKey) ?? $defaultApiKey;
$apiKeyB = Env::get('CHATTIES_API_KEY_B', $defaultApiKey) ?? $defaultApiKey;
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>chatties | Two LLMs in dialogue</title>
  <style><?php readfile(__DIR__ . '/assets/app.css'); ?></style>
</head>
<body>
  <div class="shell">
    <header class="top">
      <div>
        <h1 class="brand"><a href="./">chatties</a></h1>
        <p class="tagline" data-i18n="header.tagline">Two LLMs discuss a topic in turns — via OpenAI-compatible API URLs, with a limited number of rounds.</p>
      </div>
      <div class="lang-switch" role="group" aria-label="Language">
        <span class="lang-label" data-i18n="lang.label">Language</span>
        <button type="button" class="ghost small lang-btn" data-lang="en" aria-pressed="true">EN</button>
        <button type="button" class="ghost small lang-btn" data-lang="de" aria-pressed="false">DE</button>
      </div>
    </header>

    <section class="panel">
      <h2 data-i18n="setup.title">Setup</h2>
      <form id="setup-form" class="setup" method="post" action="#" onsubmit="return false;">
        <div class="field">
          <label for="topic" data-i18n="setup.topic">Shared topic / question</label>
          <textarea id="topic" required data-i18n-placeholder="setup.topic.placeholder" placeholder="e.g. Explain Quicksort vs. Mergesort to each other — and agree when each approach fits better."></textarea>
        </div>

        <div class="row">
          <div class="field">
            <label for="max-rounds" data-i18n="setup.maxRounds">Max. rounds (each round: every agent speaks once)</label>
            <input id="max-rounds" type="number" min="1" max="40" value="3">
          </div>
        </div>

        <div class="agents">
          <div class="agent a">
            <div class="agent-head"><span class="dot"></span> Agent A</div>
            <div class="field">
              <label for="agent-a-name" data-i18n="agent.name">Name</label>
              <input id="agent-a-name" value="Teacher" required>
            </div>
            <div class="field">
              <label for="agent-a-url" data-i18n="agent.url">API URL</label>
              <input id="agent-a-url" value="<?= htmlspecialchars($apiUrlA, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="field">
              <label for="agent-a-model" data-i18n="agent.model">Model ID (optional)</label>
              <input id="agent-a-model" value="<?= htmlspecialchars($modelA, ENT_QUOTES, 'UTF-8') ?>" data-i18n-placeholder="agent.model.placeholderA" placeholder="e.g. qwen2.5-7b-instruct">
            </div>
            <div class="field">
              <label for="agent-a-key" data-i18n="agent.key">API key (optional)</label>
              <input id="agent-a-key" type="password" autocomplete="off" value="<?= htmlspecialchars($apiKeyA, ENT_QUOTES, 'UTF-8') ?>" data-i18n-placeholder="agent.key.placeholder" placeholder="Bearer token if required">
            </div>
            <div class="field">
              <label for="agent-a-context" data-i18n="agent.contextA">Role context (A only)</label>
              <textarea id="agent-a-context" data-i18n-placeholder="agent.contextA.placeholder" placeholder="You are a patient teacher. You ask focused questions and correct gently."></textarea>
            </div>
          </div>

          <div class="agent b">
            <div class="agent-head"><span class="dot"></span> Agent B</div>
            <div class="field">
              <label for="agent-b-name" data-i18n="agent.name">Name</label>
              <input id="agent-b-name" value="Student" required>
            </div>
            <div class="field">
              <label for="agent-b-url" data-i18n="agent.url">API URL</label>
              <input id="agent-b-url" value="<?= htmlspecialchars($apiUrlB, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="field">
              <label for="agent-b-model" data-i18n="agent.model">Model ID (optional)</label>
              <input id="agent-b-model" value="<?= htmlspecialchars($modelB, ENT_QUOTES, 'UTF-8') ?>" data-i18n-placeholder="agent.model.placeholderB" placeholder="e.g. another model / port">
            </div>
            <div class="field">
              <label for="agent-b-key" data-i18n="agent.key">API key (optional)</label>
              <input id="agent-b-key" type="password" autocomplete="off" value="<?= htmlspecialchars($apiKeyB, ENT_QUOTES, 'UTF-8') ?>" data-i18n-placeholder="agent.key.placeholder" placeholder="Bearer token if required">
            </div>
            <div class="field">
              <label for="agent-b-context" data-i18n="agent.contextB">Role context (B only)</label>
              <textarea id="agent-b-context" data-i18n-placeholder="agent.contextB.placeholder" placeholder="You are a curious student. You ask when something is unclear and think out loud."></textarea>
            </div>
          </div>
        </div>

        <div class="actions">
          <button type="submit" class="primary" id="start-btn" data-i18n="actions.start">Start conversation</button>
          <button type="button" class="ghost" id="stop-btn" data-i18n="actions.stop">Stop</button>
          <span class="status" id="status" data-i18n="status.ready">Ready.</span>
        </div>
      </form>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2 data-i18n="thread.title">Thread</h2>
        <button type="button" class="ghost small" id="continue-btn" data-i18n="thread.continue" hidden>Continue</button>
      </div>
      <div class="meta" id="meta"></div>
      <div class="chat" id="chat">
        <p class="empty" data-i18n="thread.empty">No messages yet. Start a conversation.</p>
      </div>
      <div class="extend" id="extend-panel" hidden>
        <h3 data-i18n="extend.title">Continue further</h3>
        <p class="extend-hint" data-i18n="extend.hint">Grant more rounds and optionally steer the direction.</p>
        <div class="field">
          <label for="extend-directive" data-i18n="extend.directive">Nudge (optional)</label>
          <textarea id="extend-directive" data-i18n-placeholder="extend.directive.placeholder" placeholder="e.g. Please get concrete with an example and agree on a recommendation."></textarea>
        </div>
        <div class="extend-row">
          <div class="field">
            <label for="extend-rounds" data-i18n="extend.rounds">Extra rounds</label>
            <input id="extend-rounds" type="number" min="1" max="40" value="1">
          </div>
          <button type="button" class="primary" id="extend-btn" data-i18n="extend.submit">Grant rounds &amp; continue</button>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2 data-i18n="history.title">Past threads</h2>
        <button type="button" class="ghost small" id="refresh-history-btn" data-i18n="history.refresh">Refresh</button>
      </div>
      <div id="history" class="history">
        <p class="empty" data-i18n="history.empty">No threads saved yet.</p>
      </div>
    </section>
  </div>

  <script><?php readfile(__DIR__ . '/assets/i18n.js'); ?></script>
  <script><?php readfile(__DIR__ . '/assets/app.js'); ?></script>
</body>
</html>
