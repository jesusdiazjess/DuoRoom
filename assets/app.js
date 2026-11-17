/* DuoRoom: Vanilla JavaScript + WebRTC. No libraries and no WebSocket server. */
(() => {
  'use strict';

  const el = (id) => document.getElementById(id);
  const rtcConfig = window.DUOROOM_ICE || {
    iceServers: [{ urls: ['stun:stun.l.google.com:19302'] }],
  };

  let room = null;             // {room_id, role, token, code, mode, name?}
  let peer = null;
  let dataChannel = null;
  let localStream = null;
  let remoteStream = new MediaStream();
  let screenTrack = null;
  let localReady = false;
  let guestReady = false;
  let guestJoined = false;
  let offerStarted = false;
  let accepted = false;
  let connected = false;
  let closing = false;
  let cameraEnabled = true;
  let microphoneEnabled = true;
  let remoteCameraEnabled = true;
  let facingMode = 'user';
  let iceQueue = [];
  let cursor = 0;
  let pollTimer = null;
  let callTimer = null;
  let startedAt = 0;
  let consecutivePollErrors = 0;
  let toastTimer = null;
  let disconnectTimer = null;
  let currentRemoteName = 'Other participant';

  const show = (id, visible) => { el(id).hidden = !visible; };
  const setText = (id, value) => { el(id).textContent = value; };

  function toast(message, isError = false) {
    const box = el('toast');
    box.textContent = message;
    box.classList.toggle('error', isError);
    box.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { box.hidden = true; }, 5500);
  }

  async function api(action, payload = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 16000);
    let response;
    try {
      response = await fetch('signal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
        body: JSON.stringify({ action, ...payload }),
        signal: controller.signal,
      });
      if (!(response.headers.get('content-type') || '').includes('application/json')) {
        throw new Error('The server returned HTML instead of JSON. Check your InfinityFree browser security check, file paths, and PHP setup.');
      }
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || `Request failed (${response.status})`);
      return result;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('The server took too long to respond.');
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  function credentials() {
    if (!room) throw new Error('No active call.');
    return { room_id: room.room_id, role: room.role, token: room.token };
  }

  function status(message) {
    setText('callStatus', message);
    setText('connectionText', message);
  }

  function setRemoteName(name) {
    currentRemoteName = name || 'Other participant';
    setText('remoteLabel', currentRemoteName);
    setText('remotePlaceholderName', currentRemoteName);
  }

  function showScreen(which) {
    show('homeScreen', which === 'home');
    show('callScreen', which === 'call');
    show('doneScreen', which === 'done');
    window.scrollTo({ top: 0, behavior: 'auto' });
  }

  function renderVideos() {
    const hasLocalVideo = Boolean(localStream?.getVideoTracks().length && cameraEnabled) || Boolean(screenTrack);
    const hasRemoteVideo = room?.mode === 'video' && remoteStream.getVideoTracks().length > 0 && remoteCameraEnabled;
    el('localTile').classList.toggle('has-local-video', hasLocalVideo);
    el('videoStage').classList.toggle('has-remote-video', hasRemoteVideo);
    if (room?.mode === 'audio') el('videoStage').classList.add('audio-mode');
    else el('videoStage').classList.remove('audio-mode');
  }

  function renderControls() {
    if (!room) return;
    const canVideo = room.mode === 'video';
    const canControlMedia = localReady;
    el('micButton').disabled = !canControlMedia;
    el('micButton').classList.toggle('is-off', canControlMedia && !microphoneEnabled);
    el('micButton').setAttribute('aria-pressed', String(!microphoneEnabled));
    el('micButton').setAttribute('aria-label', microphoneEnabled ? 'Mute microphone' : 'Unmute microphone');
    el('micButton').querySelector('small').textContent = microphoneEnabled ? 'Microphone' : 'Unmute';
    el('cameraButton').hidden = !canVideo;
    el('flipButton').hidden = !canVideo;
    el('screenButton').hidden = !canVideo || !navigator.mediaDevices?.getDisplayMedia;
    const camUsable = canVideo && Boolean(localStream?.getVideoTracks().length);
    el('cameraButton').disabled = !canControlMedia || !camUsable;
    el('cameraButton').classList.toggle('is-off', !cameraEnabled);
    el('cameraButton').setAttribute('aria-pressed', String(!cameraEnabled));
    el('cameraButton').setAttribute('aria-label', cameraEnabled ? 'Turn off camera' : 'Turn on camera');
    el('cameraButton').querySelector('small').textContent = cameraEnabled ? 'Camera' : 'Turn on';
    el('flipButton').disabled = !canControlMedia || !camUsable;
    el('screenButton').disabled = !canControlMedia || !camUsable || !peer;
    renderVideos();
  }

  function enterCall(newRoom) {
    room = newRoom;
    closing = false;
    accepted = room.role === 'host';
    connected = false;
    localReady = false;
    guestJoined = false;
    guestReady = false;
    offerStarted = false;
    microphoneEnabled = true;
    cameraEnabled = true;
    remoteCameraEnabled = true;
    cursor = 0;
    iceQueue = [];
    remoteStream = new MediaStream();
    facingMode = 'user';
    screenTrack = null;
    el('remoteVideo').srcObject = null;
    el('localVideo').srcObject = null;
    show('soundButton', false);
    show('connectingIndicator', true);
    show('callDuration', false);
    setText('callDuration', '00:00');
    setText('modePill', room.mode === 'video' ? 'Video call' : 'Voice call');
    setText('callTitle', room.role === 'host' ? 'Your private call' : 'Incoming private call');
    setRemoteName(room.role === 'host' ? 'Waiting for your guest' : 'Administrator');
    setText('remotePlaceholderDetail', room.role === 'host'
      ? 'Share your invitation code to get started.'
      : 'You have been invited to a private call.');
    show('inviteBox', room.role === 'host');
    show('incomingOverlay', room.role === 'guest');
    if (room.role === 'host') {
      setText('activeCode', room.code);
      status('Waiting for someone to use your invitation code');
    } else {
      setText('incomingTitle', 'Administrator is calling');
      setText('incomingDescription', `Join this private ${room.mode === 'video' ? 'video' : 'voice'} call. Your microphone${room.mode === 'video' ? ' and camera' : ''} will be requested only if you accept.`);
      status('Invitation received — choose Accept or Decline');
    }
    renderControls();
    showScreen('call');
    schedulePoll(300);
  }

  async function getLocalMedia() {
    if (localReady && localStream) return; // Reuse tracks when retrying a signal.
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error('Camera/microphone unavailable. Open the site over HTTPS (or localhost).');
    }
    const audio = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
    let stream;
    if (room.mode === 'video') {
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio,
          video: { facingMode: { ideal: facingMode }, width: { ideal: 1280 }, height: { ideal: 720 } },
        });
      } catch (err) {
        // No camera or video permission? Allow the conversation to proceed with audio.
        try {
          stream = await navigator.mediaDevices.getUserMedia({ audio, video: false });
          toast('Camera unavailable. Continuing with microphone only.');
          cameraEnabled = false;
        } catch (_) {
          throw err;
        }
      }
    } else {
      stream = await navigator.mediaDevices.getUserMedia({ audio, video: false });
    }
    localStream = stream;
    localReady = true;
    el('localVideo').srcObject = stream;
    el('localVideo').play().catch(() => {});
    cameraEnabled = stream.getVideoTracks().length > 0;
    microphoneEnabled = stream.getAudioTracks().some((track) => track.enabled);
    renderControls();
  }

  async function sendSignal(kind, payload) {
    // A small retry handles transient network errors without adding a server component.
    let lastError;
    for (let attempt = 0; attempt < 3; attempt++) {
      if (closing || !room) return;
      try {
        return await api('send_signal', { ...credentials(), kind, payload });
      } catch (error) {
        lastError = error;
        if (attempt < 2) await new Promise((resolve) => setTimeout(resolve, (attempt + 1) * 550));
      }
    }
    if (!closing) toast(`Could not send ${kind}: ${lastError.message}`, true);
    throw lastError;
  }

  function sendControl(message) {
    if (dataChannel?.readyState === 'open') {
      try { dataChannel.send(JSON.stringify(message)); } catch (_) { /* closing */ }
    }
  }

  function attachChannel(channel) {
    dataChannel = channel;
    channel.onopen = () => {
      sendControl({ type: 'camera', enabled: cameraEnabled && room?.mode === 'video' });
      // Once the data channel works, signaling polling is no longer needed.
      if (connected) clearTimeout(pollTimer);
    };
    channel.onmessage = (event) => {
      let message;
      try { message = JSON.parse(event.data); } catch (_) { return; }
      if (message.type === 'bye') {
        finishCall('The other participant has ended the call.', false);
      } else if (message.type === 'camera') {
        remoteCameraEnabled = Boolean(message.enabled);
        renderVideos();
      }
    };
  }

  function ensurePeer() {
    if (peer) return peer;
    peer = new RTCPeerConnection(rtcConfig);
    remoteStream = new MediaStream();
    el('remoteVideo').srcObject = remoteStream;
    for (const track of localStream.getTracks()) peer.addTrack(track, localStream);

    peer.ontrack = (event) => {
      if (!remoteStream.getTracks().some((track) => track.id === event.track.id)) {
        remoteStream.addTrack(event.track);
      }
      el('remoteVideo').srcObject = remoteStream;
      event.track.onunmute = renderVideos;
      event.track.onended = renderVideos;
      renderVideos();
      el('remoteVideo').play().then(() => show('soundButton', false)).catch(() => {
        show('soundButton', true);
        toast('Browser blocked automatic sound. Tap Enable sound to hear the call.');
      });
    };
    peer.onicecandidate = (event) => {
      if (event.candidate && !closing) {
        sendSignal('ice', event.candidate.toJSON()).catch(() => {});
      }
    };
    peer.onconnectionstatechange = () => {
      if (!peer || closing) return;
      clearTimeout(disconnectTimer);
      switch (peer.connectionState) {
        case 'connected':
          if (!connected) markConnected();
          else status('Connected · end-to-end encrypted by WebRTC');
          break;
        case 'disconnected':
          status('Connection interrupted — attempting to reconnect');
          disconnectTimer = setTimeout(() => {
            if (peer?.connectionState === 'disconnected' && !closing) {
              finishCall('The connection was lost. Please start a new private call.', true);
            }
          }, 18000);
          break;
        case 'failed':
          status('Connection failed — a TURN relay may be required on this network');
          toast('Could not establish a media path. Try another network or configure TURN.', true);
          break;
        case 'connecting':
          status('Establishing a secure connection...');
          break;
        default: break;
      }
    };

    if (room.role === 'host') attachChannel(peer.createDataChannel('duoroom-control'));
    else peer.ondatachannel = (event) => attachChannel(event.channel);
    renderControls();
    return peer;
  }

  function markConnected() {
    connected = true;
    startedAt = Date.now();
    status('Connected · end-to-end encrypted by WebRTC');
    show('connectingIndicator', false);
    show('callDuration', true);
    clearInterval(callTimer);
    callTimer = setInterval(() => {
      const seconds = Math.floor((Date.now() - startedAt) / 1000);
      const minutes = Math.floor(seconds / 60).toString().padStart(2, '0');
      const sec = (seconds % 60).toString().padStart(2, '0');
      setText('callDuration', `${minutes}:${sec}`);
    }, 1000);
    if (dataChannel?.readyState === 'open') clearTimeout(pollTimer);
    renderVideos();
    el('remoteVideo').play().catch(() => show('soundButton', true));
  }

  async function flushIce() {
    while (iceQueue.length && peer?.remoteDescription) {
      const candidate = iceQueue.shift();
      try { await peer.addIceCandidate(candidate); }
      catch (error) { console.warn('Could not add an ICE candidate:', error); }
    }
  }

  async function makeOfferIfReady() {
    if (!room || room.role !== 'host' || !guestReady || !localReady || offerStarted || closing) return;
    offerStarted = true;
    try {
      const connection = ensurePeer();
      status('Connecting to your guest...');
      const offer = await connection.createOffer();
      await connection.setLocalDescription(offer);
      await sendSignal('offer', connection.localDescription.toJSON());
      schedulePoll(200);
    } catch (error) {
      offerStarted = false;
      toast(`Cannot create call offer: ${error.message}`, true);
    }
  }

  async function receiveSignal(message) {
    if (closing) return;
    const payload = message.payload || {};
    if (message.kind === 'ready' && room.role === 'host') {
      guestReady = true;
      status('Your guest has accepted the call');
      await makeOfferIfReady();
    } else if (message.kind === 'offer' && room.role === 'guest') {
      if (!accepted || !localReady || !peer) return;
      await peer.setRemoteDescription(payload);
      await flushIce();
      const answer = await peer.createAnswer();
      await peer.setLocalDescription(answer);
      await sendSignal('answer', peer.localDescription.toJSON());
      status('Connecting to administrator...');
    } else if (message.kind === 'answer' && room.role === 'host') {
      if (!peer) return;
      await peer.setRemoteDescription(payload);
      await flushIce();
    } else if (message.kind === 'ice') {
      if (!peer?.remoteDescription) iceQueue.push(payload);
      else {
        try { await peer.addIceCandidate(payload); }
        catch (error) { console.warn('Invalid ICE candidate:', error); }
      }
    }
  }

  function schedulePoll(delay) {
    clearTimeout(pollTimer);
    if (!room || closing || (connected && dataChannel?.readyState === 'open')) return;
    pollTimer = setTimeout(poll, delay);
  }

  async function poll() {
    if (!room || closing || (connected && dataChannel?.readyState === 'open')) return;
    try {
      const data = await api('poll', { ...credentials(), cursor });
      if (closing) return;
      consecutivePollErrors = 0;
      if (data.status === 'ended') {
        finishCall('This call has ended or expired.', false);
        return;
      }
      if (room.role === 'host' && data.guest_name && !guestJoined) {
        guestJoined = true;
        setRemoteName(data.guest_name);
        status('Guest joined — waiting for them to accept');
        setText('remotePlaceholderDetail', 'Your invitation was redeemed. Waiting for acceptance.');
      }
      for (const signal of data.signals) {
        // Process in order. Do not advance the cursor if signal handling fails.
        await receiveSignal(signal);
        cursor = signal.id;
      }
      // 4.5s while idle; 1.4s while negotiating; no polling while connected
      // with a working WebRTC data channel (important for shared-hosting limits).
      let interval = !guestJoined && room.role === 'host' ? 4500 : 1400;
      if (connected) interval = 15000; // fallback when the data channel cannot open
      else if (room.role === 'guest' && !accepted) interval = 4000;
      schedulePoll(data.signals.length === 80 ? 50 : interval);
    } catch (error) {
      if (closing) return;
      consecutivePollErrors++;
      if (consecutivePollErrors === 3) toast(`Signaling connection issue: ${error.message}`, true);
      schedulePoll(Math.min(12000, 2000 * consecutivePollErrors));
    }
  }

  function releaseMedia() {
    clearTimeout(disconnectTimer);
    clearTimeout(pollTimer);
    clearInterval(callTimer);
    if (screenTrack) { screenTrack.onended = null; screenTrack.stop(); screenTrack = null; }
    if (dataChannel) { try { dataChannel.close(); } catch (_) { /* ignore */ } dataChannel = null; }
    if (peer) { peer.onconnectionstatechange = null; peer.ontrack = null; peer.close(); peer = null; }
    if (localStream) { localStream.getTracks().forEach((track) => track.stop()); localStream = null; }
    el('localVideo').srcObject = null;
    el('remoteVideo').srcObject = null;
    localReady = false;
    connected = false;
  }

  function finishCall(reason, sendServer) {
    if (closing) return;
    closing = true;
    const roomCredentials = room ? credentials() : null;
    if (sendServer && roomCredentials) {
      sendControl({ type: 'bye' });
      // Give the peer data channel a chance to flush the bye packet before closing.
      setTimeout(releaseMedia, 180);
      api('end_room', roomCredentials).catch((error) => console.warn('Could not report hangup:', error));
    } else {
      releaseMedia();
    }
    room = null;
    show('incomingOverlay', false);
    setText('doneDescription', reason);
    showScreen('done');
  }

  async function hostStart(mode) {
    if (room) return;
    el('createVideo').disabled = true;
    el('createAudio').disabled = true;
    try {
      const result = await api('create_room', { mode });
      enterCall(result);
      status('Allow microphone/camera access to prepare the call');
      try {
        await getLocalMedia();
      } catch (error) {
        toast(`Microphone access required: ${error.message}`, true);
        finishCall('Could not access microphone. Check browser permissions and try again.', true);
        return;
      }
      if (!closing) {
        status(guestJoined ? 'Guest joined — waiting for them to accept' : 'Waiting for someone to join with your code');
        await makeOfferIfReady();
      }
    } catch (error) {
      toast(error.message, true);
    } finally {
      el('createVideo').disabled = false;
      el('createAudio').disabled = false;
    }
  }

  async function guestJoin(event) {
    event.preventDefault();
    el('joinButton').disabled = true;
    try {
      const name = el('guestName').value.trim();
      const code = el('joinCode').value.trim();
      const result = await api('join_room', { name, code });
      enterCall(result);
    } catch (error) {
      toast(error.message, true);
    } finally {
      el('joinButton').disabled = false;
    }
  }

  async function guestAccept() {
    if (!room || room.role !== 'guest' || accepted) return;
    el('acceptButton').disabled = true;
    status('Requesting your microphone and camera...');
    try {
      await getLocalMedia();
      if (closing) return;
      accepted = true;
      show('incomingOverlay', false);
      ensurePeer();
      await sendSignal('ready', { accepted: true });
      status('Waiting for administrator to connect...');
      schedulePoll(100);
    } catch (error) {
      accepted = false;
      show('incomingOverlay', true);
      status('Could not accept the call — check permissions and try again');
      toast(`Cannot accept call: ${error.message}`, true);
    } finally {
      el('acceptButton').disabled = false;
    }
  }

  function updateVideoToggle() {
    if (!localStream) return;
    if (screenTrack) { toast('Stop sharing your screen before changing your camera.'); return; }
    cameraEnabled = !cameraEnabled;
    localStream.getVideoTracks().forEach((track) => { track.enabled = cameraEnabled; });
    sendControl({ type: 'camera', enabled: cameraEnabled });
    renderControls();
  }

  async function flipCamera() {
    if (!localStream || screenTrack) return;
    const oldTrack = localStream.getVideoTracks()[0];
    if (!oldTrack) return;
    const nextFacing = facingMode === 'user' ? 'environment' : 'user';
    try {
      const replacement = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: nextFacing } }, audio: false });
      const fresh = replacement.getVideoTracks()[0];
      const sender = peer?.getSenders().find((item) => item.track?.id === oldTrack.id);
      if (sender) await sender.replaceTrack(fresh);
      localStream.removeTrack(oldTrack);
      localStream.addTrack(fresh);
      fresh.enabled = cameraEnabled;
      oldTrack.stop();
      facingMode = nextFacing;
      el('localVideo').srcObject = localStream;
      renderVideos();
    } catch (error) {
      toast(`Cannot switch cameras: ${error.message}`, true);
    }
  }

  async function toggleScreenShare() {
    if (!room || room.mode !== 'video' || !peer || !localStream) return;
    const sender = peer.getSenders().find((item) => item.track?.kind === 'video');
    if (!sender) return toast('Screen sharing requires a camera track at call start.', true);
    if (screenTrack) {
      const old = screenTrack;
      screenTrack = null;
      old.onended = null;
      try { await sender.replaceTrack(localStream.getVideoTracks()[0]); }
      catch (error) { toast(error.message, true); }
      old.stop();
      el('localVideo').srcObject = localStream;
      el('localVideo').style.transform = '';
      el('screenButton').querySelector('small').textContent = 'Share screen';
      sendControl({ type: 'camera', enabled: cameraEnabled });
      renderVideos();
      return;
    }
    try {
      const screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
      const track = screenStream.getVideoTracks()[0];
      await sender.replaceTrack(track);
      screenTrack = track;
      el('localVideo').srcObject = new MediaStream([track]);
      el('localVideo').style.transform = 'none';
      el('screenButton').querySelector('small').textContent = 'Stop share';
      track.onended = () => { if (screenTrack === track) toggleScreenShare().catch(console.warn); };
      sendControl({ type: 'camera', enabled: true });
      renderVideos();
    } catch (error) {
      if (error.name !== 'NotAllowedError') toast(`Screen sharing failed: ${error.message}`, true);
    }
  }

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      toast('Copied to clipboard.');
    } catch (_) {
      // Works in browsers that restrict Clipboard API.
      window.prompt('Copy this invitation:', text);
    }
  }

  async function shareInvite() {
    if (!room) return;
    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('code', room.code);
    const text = `Join my private ${room.mode} call on DuoRoom. Invitation code: ${room.code}`;
    if (navigator.share) {
      try { await navigator.share({ title: 'Private DuoRoom call', text, url: url.href }); return; }
      catch (error) { if (error.name === 'AbortError') return; }
    }
    await copyText(`${text}\n${url.href}`);
  }

  function bindEvents() {
    el('adminForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      el('loginButton').disabled = true;
      try {
        await api('login', { username: el('adminUsername').value.trim(), password: el('adminPassword').value });
        showAdmin(true);
        el('adminPassword').value = '';
        toast('Signed in. You can now create a private call.');
      } catch (error) { toast(error.message, true); }
      finally { el('loginButton').disabled = false; }
    });
    el('logoutButton').addEventListener('click', async () => {
      try { await api('logout'); showAdmin(false); toast('Signed out.'); }
      catch (error) { toast(error.message, true); }
    });
    el('changePasswordForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await api('change_password', {
          current_password: el('currentPassword').value,
          new_password: el('newPassword').value,
        });
        el('changePasswordForm').reset();
        toast('Administrator password updated successfully.');
      } catch (error) { toast(error.message, true); }
    });
    el('createVideo').addEventListener('click', () => hostStart('video'));
    el('createAudio').addEventListener('click', () => hostStart('audio'));
    el('joinForm').addEventListener('submit', guestJoin);
    el('joinCode').addEventListener('input', () => {
      const chars = el('joinCode').value.toUpperCase().replace(/[^A-Z2-9]/g, '').slice(0, 12);
      el('joinCode').value = [chars.slice(0, 4), chars.slice(4, 8), chars.slice(8, 12)].filter(Boolean).join('-');
    });
    el('acceptButton').addEventListener('click', guestAccept);
    el('declineButton').addEventListener('click', () => finishCall('You declined the private invitation.', true));
    el('hangupButton').addEventListener('click', () => finishCall('Your private conversation has ended.', true));
    el('micButton').addEventListener('click', () => {
      if (!localStream) return;
      microphoneEnabled = !microphoneEnabled;
      localStream.getAudioTracks().forEach((track) => { track.enabled = microphoneEnabled; });
      renderControls();
    });
    el('cameraButton').addEventListener('click', updateVideoToggle);
    el('flipButton').addEventListener('click', flipCamera);
    el('screenButton').addEventListener('click', toggleScreenShare);
    el('soundButton').addEventListener('click', async () => {
      try { await el('remoteVideo').play(); show('soundButton', false); }
      catch (error) { toast(`Sound still blocked: ${error.message}`, true); }
    });
    el('fullscreenButton').addEventListener('click', async () => {
      try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await el('videoStage').requestFullscreen();
      } catch (_) { toast('Fullscreen is not supported in this browser.'); }
    });
    el('copyCode').addEventListener('click', () => { if (room) copyText(room.code); });
    el('shareInvite').addEventListener('click', shareInvite);
    el('homeButton').addEventListener('click', () => window.location.assign(window.location.pathname));
    // No beforeunload network requests: the room will expire automatically.
    window.addEventListener('pagehide', releaseMedia);
  }

  function showAdmin(isAdmin) {
    show('adminForm', !isAdmin);
    show('adminControls', isAdmin);
  }

  async function initialize() {
    bindEvents();
    const invite = new URLSearchParams(window.location.search).get('code');
    if (invite) {
      el('joinCode').value = invite;
      el('joinCode').dispatchEvent(new Event('input'));
      el('guestName').focus();
    }
    try {
      const data = await api('whoami');
      showAdmin(Boolean(data.authenticated));
    } catch (error) {
      // The guest form is still usable if the session check encounters a hiccup.
      console.warn('Authentication status unavailable:', error);
      toast(`Cannot reach the server: ${error.message}`, true);
    }
  }

  initialize();
})();
