<?php
// Only public ICE-server information is exposed; database credentials never leave PHP.
$config = require __DIR__ . '/config.php';
$ice = json_encode(['iceServers' => $config['ice_servers']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#0e1119">
  <meta name="description" content="Private one-on-one video and audio calls. Join with an administrator invitation code.">
  <title>DuoRoom — Private audio & video calls</title>
  <link rel="stylesheet" href="assets/style.css?v=1.0.0">
  <script>window.DUOROOM_ICE = <?= $ice ?: '{"iceServers":[]}' ?>;</script>
  <script defer src="assets/app.js?v=1.0.0"></script>
</head>
<body>
  <div class="page-glow" aria-hidden="true"></div>
  <header class="topbar wrap">
    <a href="./" class="brand" aria-label="DuoRoom home">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="12" height="14" rx="4"/><path d="m15 10 5-3v10l-5-3"/></svg>
      </span>
      <span>Duo<span class="brand-emphasis">Room</span></span>
    </a>
    <span class="header-pill"><span class="pulse-dot"></span> Private, one-to-one calls</span>
  </header>

  <main class="wrap">
    <section id="homeScreen" class="home-screen">
      <div class="hero">
        <div class="eyebrow">CONNECT MORE PERSONALLY</div>
        <h1>A little closer,<br><span>wherever you are.</span></h1>
        <p>Clear audio, face-to-face video, and a private space made for just two. Only someone with the administrator's invitation code can join.</p>
      </div>

      <div class="home-grid">
        <article class="panel host-panel">
          <div class="panel-heading">
            <span class="panel-icon host-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 15v2"/></svg>
            </span>
            <div><h2>Start a private call</h2><p>Administrator only</p></div>
          </div>
          <form id="adminForm" class="stacked-form">
            <label for="adminUsername">Username</label>
            <input id="adminUsername" type="text" autocomplete="username" value="admin" maxlength="50" required>
            <label for="adminPassword">Password</label>
            <input id="adminPassword" type="password" autocomplete="current-password" placeholder="Enter your admin password" required>
            <button class="button button-primary button-wide" type="submit" id="loginButton">Sign in as administrator</button>
          </form>
          <div id="adminControls" hidden>
            <div class="signed-in"><span class="success-dot"></span> Signed in as administrator <button type="button" id="logoutButton" class="text-button">Sign out</button></div>
            <p class="section-intro">Choose how you'd like to connect. We'll generate a unique private invitation code.</p>
            <div class="call-choices">
              <button type="button" id="createVideo" class="choice-card">
                <span class="choice-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="13" height="14" rx="3"/><path d="m16 10 5-3v10l-5-3"/></svg>
                </span>
                <span><strong>Video call</strong><small>See and hear each other</small></span>
                <span class="choice-arrow">↗</span>
              </button>
              <button type="button" id="createAudio" class="choice-card">
                <span class="choice-icon audio-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"/><path d="M5 10v1a7 7 0 0 0 14 0v-1M12 18v4m-4 0h8"/></svg>
                </span>
                <span><strong>Voice call</strong><small>Just your voices</small></span>
                <span class="choice-arrow">↗</span>
              </button>
            </div>
            <details class="account-details">
              <summary>Change administrator password</summary>
              <form id="changePasswordForm" class="stacked-form compact-form">
                <label for="currentPassword">Current password</label>
                <input id="currentPassword" type="password" autocomplete="current-password" required>
                <label for="newPassword">New password (12+ characters)</label>
                <input id="newPassword" type="password" autocomplete="new-password" minlength="12" required>
                <button type="submit" class="button button-outline">Update password</button>
              </form>
            </details>
          </div>
        </article>

        <article class="panel join-panel">
          <div class="panel-heading">
            <span class="panel-icon join-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12H4m8-8-8 8 8 8"/><path d="M14 5h4a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-4"/></svg>
            </span>
            <div><h2>Join a call</h2><p>Have an invitation? You're in the right place.</p></div>
          </div>
          <form id="joinForm" class="stacked-form">
            <label for="guestName">Your display name</label>
            <input id="guestName" type="text" placeholder="What should we call you?" autocomplete="nickname" maxlength="50" required>
            <label for="joinCode">Invitation code</label>
            <input id="joinCode" type="text" placeholder="XXXX-XXXX-XXXX" autocapitalize="characters" spellcheck="false" maxlength="20" class="code-input" required>
            <button type="submit" id="joinButton" class="button button-light button-wide">Join private call <span aria-hidden="true">↗</span></button>
          </form>
          <div class="privacy-row"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg><span>Codes are private and admit only one guest.</span></div>
        </article>
      </div>
      <div class="home-footnote">Peer-to-peer calls powered by WebRTC · No account needed for guests · HTTPS required online</div>
    </section>

    <section id="callScreen" class="call-screen" hidden>
      <div class="call-topline">
        <div class="call-person">
          <span id="callPersonIcon" class="person-icon">✦</span>
          <div>
            <h1 id="callTitle">Your private call</h1>
            <div id="callStatus" class="status-text" role="status">Preparing your call...</div>
          </div>
        </div>
        <div class="call-topright">
          <span id="callDuration" class="duration" hidden>00:00</span>
          <span id="modePill" class="mode-pill">Video call</span>
        </div>
      </div>

      <div id="inviteBox" class="invite-bar" hidden>
        <div class="invite-copy">
          <div class="invite-label">Your private invitation code</div>
          <div id="activeCode" class="invite-code">---- ---- ----</div>
        </div>
        <div class="invite-actions">
          <button id="copyCode" type="button" class="button button-outline button-small">Copy code</button>
          <button id="shareInvite" type="button" class="button button-primary button-small">Share invitation</button>
        </div>
      </div>

      <div class="video-stage" id="videoStage">
        <video id="remoteVideo" autoplay playsinline></video>
        <div id="remotePlaceholder" class="remote-placeholder">
          <div id="remoteAvatar" class="remote-avatar">✦</div>
          <h2 id="remotePlaceholderName">Waiting for someone to join</h2>
          <p id="remotePlaceholderDetail">Share your invitation code to get started.</p>
        </div>
        <div id="connectingIndicator" class="connecting-indicator"><span class="spinner"></span><span id="connectionText">Getting everything ready</span></div>
        <div class="remote-label" id="remoteLabel">Other participant</div>
        <div class="local-tile" id="localTile">
          <video id="localVideo" autoplay muted playsinline></video>
          <div id="localPlaceholder" class="local-placeholder">You</div>
          <span class="local-name">You</span>
        </div>

        <div id="incomingOverlay" class="incoming-overlay" hidden>
          <div class="incoming-card">
            <div class="incoming-avatar">✦</div>
            <div class="eyebrow">PRIVATE INVITATION</div>
            <h2 id="incomingTitle">Administrator is calling</h2>
            <p id="incomingDescription">A private video call is waiting for you.</p>
            <div class="incoming-buttons">
              <button type="button" id="declineButton" class="button button-decline">Decline</button>
              <button type="button" id="acceptButton" class="button button-primary">Accept call</button>
            </div>
          </div>
        </div>
      </div>

      <div class="call-toolbar" aria-label="Call controls">
        <div class="toolbar-group">
          <button type="button" id="micButton" class="tool-button" title="Mute microphone" aria-label="Mute microphone" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3Z"/><path d="M5 10v1a7 7 0 0 0 14 0v-1M12 18v4m-4 0h8"/></svg>
            <small>Microphone</small>
          </button>
          <button type="button" id="cameraButton" class="tool-button" title="Turn off camera" aria-label="Turn off camera" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="13" height="14" rx="3"/><path d="m16 10 5-3v10l-5-3"/></svg>
            <small>Camera</small>
          </button>
          <button type="button" id="flipButton" class="tool-button" title="Switch camera" aria-label="Switch camera">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8a9 9 0 0 1 15.5-4.5L21 6M21 16a9 9 0 0 1-15.5 4.5L3 18"/><path d="M21 2v4h-4M3 22v-4h4"/><rect x="8.5" y="9" width="7" height="6" rx="1"/></svg>
            <small>Flip</small>
          </button>
          <button type="button" id="screenButton" class="tool-button" title="Share screen" aria-label="Share screen">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="15" rx="2"/><path d="M8 22h8M12 18v4M9 11l3-3 3 3m-3-3v7"/></svg>
            <small>Share screen</small>
          </button>
          <button type="button" id="soundButton" class="tool-button tool-sound" title="Enable incoming sound" aria-label="Enable incoming sound" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m11 5-5 4H3v6h3l5 4V5ZM15 9a5 5 0 0 1 0 6m3-9a9 9 0 0 1 0 12"/></svg>
            <small>Enable sound</small>
          </button>
          <button type="button" id="fullscreenButton" class="tool-button" title="Fullscreen" aria-label="Fullscreen">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg>
            <small>Fullscreen</small>
          </button>
        </div>
        <button type="button" id="hangupButton" class="button hangup-button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 16.5a1.7 1.7 0 0 1-2 1.1l-3.1-.8a1.7 1.7 0 0 1-1.3-1.6v-2.1a13 13 0 0 0-4.2 0v2.1a1.7 1.7 0 0 1-1.3 1.6l-3.1.8a1.7 1.7 0 0 1-2-1.1l-1-3c-.3-1 .1-2 .9-2.7a15.2 15.2 0 0 1 17.2 0c.8.7 1.2 1.7.9 2.7l-1 3Z"/></svg>
          <span>End call</span>
        </button>
      </div>
      <div class="call-hint">Audio and video are sent directly between browsers when a peer-to-peer route is available.</div>
    </section>

    <section id="doneScreen" class="done-screen" hidden>
      <div class="done-symbol">✓</div>
      <h1>Call ended</h1>
      <p id="doneDescription">Your private conversation has ended.</p>
      <button type="button" id="homeButton" class="button button-primary">Back to home</button>
    </section>
  </main>
  <div class="toast" id="toast" role="alert" aria-live="assertive" hidden></div>
</body>
</html>
