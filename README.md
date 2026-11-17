# DuoRoom — Private 1-on-1 video + audio calling (PHP, MySQL, WebRTC)

A responsive, Google-Duo-inspired **prototype**, without Google branding. No Node, Composer, sockets, paid libraries, or build tools required. PHP+MySQL carry the invite/authentication/SDP/ICE metadata only; browsers stream their own media using WebRTC.

## Your administrator login

- **Username:** `admin`
- **Initial password:** `aOa0FO71i-9J5RBgiZFhDA23Edc`

**Change this password immediately after your first successful login**, using the "Change administrator password" form. Never share this password. Only the invite code should be given to a guest.

The password is uniquely generated for THIS ZIP and is stored **only as a password hash** in `schema.sql`. The plaintext exists in this README for the owner. **Do not upload README.md or schema.sql into your public htdocs directory.**

## Project layout

```text
DuoRoom ZIP/
├─ README.md              <-- installation instructions; DO NOT upload
├─ schema.sql             <-- import into phpMyAdmin; DO NOT upload
└─ public/                <-- upload the CONTENTS of this folder
   ├─ .htaccess           <-- blocks external access to PHP config files
   ├─ config.php           <-- set InfinityFree MySQL credentials / optional TURN
   ├─ db.php               <-- PDO MySQL connection
   ├─ signal.php           <-- same-origin JSON API
   ├─ index.php            <-- responsive calling UI
   └─ assets/
      ├─ app.js            <-- browser WebRTC, fetch, UI logic
      └─ style.css         <-- responsive dark theme
```

## InfinityFree deployment

1. **Create a MySQL database** using InfinityFree's hosting control panel: **MySQL Databases**. Copy the exact host, username, database name, and MySQL/hosting password. InfinityFree DB host is normally something like `sql123.infinityfree.com`, **not `localhost`**.
2. Open phpMyAdmin from the panel. **Select the new database** on the left, then choose **Import**, select `schema.sql`, and click **Go**. The script creates `dc_admins`, `dc_rooms`, and `dc_signals` and seeds the administrator's password hash. Do not execute `CREATE DATABASE` on InfinityFree.
3. Edit `public/config.php` to enter those four MySQL settings. Save the file without any extra characters before `<?php`.
4. Via File Manager or FTP, upload **the contents of `public/`** into `htdocs/call/` (create the `call` folder if needed), including `.htaccess` and `assets/`. Alternatively use `htdocs/` to serve from the root. **Do not upload the README or schema.sql.** If using File Manager, confirm hidden `.htaccess` transferred correctly.
5. Access your site using **HTTPS**: `https://YOUR_DOMAIN/call/` (or the root if you uploaded directly into `htdocs`). Test the URL in a regular browser first so InfinityFree can complete its browser cookie/security check. The microphone and camera APIs will not run over arbitrary HTTP websites.
6. Sign in as `admin` with the initial password above. Immediately change it using the built-in password-change form.
7. Click **Video call** or **Voice call**, allow browser microphone/camera access, and click **Share invitation** or **Copy code**. Send the code ONLY to the intended guest.
8. On a second computer/phone or second browser profile, open the same HTTPS web address. Enter a display name + code (or use the shared URL), then **Accept call** and allow mic/camera access. The call connects through WebRTC.

### Security and invitation rules

- Only a logged-in administrator can create rooms. Guest access requires a 12-character random invitation code.
- Each invitation is **one-use** and binds to one guest. A third client receives an error.
- This is **one admin + one guest**, not group calling.
- Each participant receives a long random room token, stored as SHA-256 hash in MySQL. Every send/poll/end request is validated.
- Room codes expire after 3 hours (configurable in `config.php`). Creating a new room closes the previous room. Old rooms/signals are periodically cleaned when the administrator creates another room.
- A code is a **bearer invitation**, meaning anyone who gets it can claim the only guest seat first. It is not personal-ID verification. Keep codes private. Use HTTPS.
- Refreshing or leaving during a call ends local WebRTC media; **rooms cannot automatically recover** after refreshing in this prototype. Create a fresh room and code. This is not a complete Google Duo clone and has no push notifications, contacts, call history, or call recording.

## Microphone, camera and call controls

- Select separate **voice-only** and **video** call types.
- Guests explicitly Accept or Decline. The camera and microphone are requested only on accepting a guest invitation.
- In a video call: camera on/off, microphone mute/unmute, front/rear camera (supported phones), and screen sharing (supported browsers). Fullscreen on compatible browsers.
- Both participants can hang up. The browser data channel conveys hang-up instantly after connection; PHP is also updated for calls still connecting.
- A call timer and connection status are shown. There is a manual Enable sound button for browsers that block autoplay.
- If a camera is unavailable during a video call, the app attempts to continue with audio only. An operational microphone is mandatory.

## Local XAMPP/LAMP setup

1. Create a MySQL database called `duoroom` in phpMyAdmin.
2. Select `duoroom`, then import `schema.sql`.
3. Edit `public/config.php` to set `host=127.0.0.1`, `name=duoroom`, `user=root`, and `pass=''` (if you use XAMPP's default passwordless MySQL setup).
4. Copy **contents** of `public/` into `C:\xampp\htdocs\duoroom\`.
5. Open `http://localhost/duoroom/` (localhost is a secure context for WebRTC). Sign in and test with two separate browser profiles or two devices with HTTPS.

## TURN / ICE caveat

This project includes Google's publicly available STUN endpoints, which help discover network paths. **STUN does not relay media** and cannot guarantee a connection on mobile networks, symmetric NATs, locked-down office Wi-Fi, or restrictive firewalls. **For dependable real-world calls, configure a TURN relay** in `public/config.php` by adding a `turn:`/`turns:` server and its authorized `username` and `credential`. TURN is a separate service that InfinityFree does not provide in this project. Browser-to-browser WebRTC media stays outside the InfinityFree PHP server even when a TURN relay is used.

## InfinityFree free-hosting limits

InfinityFree's own support docs warn against repeated, rapid AJAX background polling. DuoRoom polls every few seconds only while waiting for the guest / negotiating. Once the WebRTC data channel is connected it stops polling, so the actual call does not generate a constant stream of PHP requests. **This reduces server load but does not guarantee the free plan can support every usage pattern or a long call.** InfinityFree can also serve its browser security HTML to clients that do not complete the browser check; this project is intended for normal browsers using same-origin AJAX, not Android native apps, cURL or third-party API clients.

For dependable or frequent calling, host signaling on a service designed for real-time use (such as a WebSocket server) plus a TURN relay, rather than InfinityFree free hosting.

## Quick troubleshooting

- **"Database unavailable"**: check `config.php`, MySQL hostname (not localhost on InfinityFree), exact full database name, credentials, and successful schema import.
- **"Server returned HTML instead of JSON"**: ensure same exact host/subdomain and HTTPS, open the homepage first in the browser to complete InfinityFree's JavaScript/cookie security system. Check `signal.php` path, PHP errors, `.htaccess`, and hosting restrictions. Do not call from another domain.
- **Guest gets "Invitation not found, expired, or already used"**: first visitor with the code has claimed the only seat. Admin should create a new room/code.
- **"No microphone" / no camera**: browser site permissions, HTTPS, hardware access. Try the other browser/device. A single camera sometimes cannot be used by two tabs simultaneously.
- **Shows Connecting indefinitely**: inspect Browser DevTools Console/Network; the network may need a TURN relay. Test both clients on the same Wi-Fi first. Check TURN credentials if configured.
- **Remote video appears but sound is muted**: click **Enable sound** and check the device output volume. Local preview is muted intentionally to prevent echo.
- **Long usage or many users**: InfinityFree may rate-limit/suspend the site due to polling; migrate the signaling backend for ongoing use.

## Technical endpoints (all POST JSON to signal.php)

- `whoami`, `login`, `logout`, `change_password`
- `create_room` (admin), `join_room` (code)
- `send_signal` (`ready`, `offer`, `answer`, `ice`), `poll` (`cursor`), `end_room`

The browser uses WebRTC for encrypted audio/video and the peer-to-peer control data channel. MySQL stores only room and signaling metadata, not actual media.
