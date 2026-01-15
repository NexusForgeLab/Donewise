// --- 1. Notification Permission Logic ---
document.addEventListener('DOMContentLoaded', () => {
  if ("Notification" in window && Notification.permission === 'default') {
    const box = document.getElementById('notif-setup');
    if (box) box.style.display = 'block';
  }
});

function enableNotifications() {
  if (!("Notification" in window)) {
    alert("This browser does not support notifications.");
    return;
  }
  Notification.requestPermission().then(p => {
    if (p === "granted") {
      new Notification("Group List", { body: "Instant Alerts Enabled!", icon: "assets/icon-192.png" });
      const box = document.getElementById('notif-setup');
      if (box) box.style.display = 'none';
      connectStream();
    } else {
      alert("Permission denied.");
    }
  });
}

// --- 2. INSTANT NOTIFICATIONS (Server-Sent Events) ---
let eventSource = null;

function connectStream() {
  if (eventSource) return; 

  // Use relative path to handle subfolders
  eventSource = new EventSource("api/stream.php");

  eventSource.onmessage = function(e) {
    try {
      const data = JSON.parse(e.data);
      
      if (data.type === 'notification') {
        const badge = document.getElementById('unreadBadge');
        if (badge) {
          badge.textContent = data.unread;
          badge.style.display = (data.unread > 0) ? '' : 'none';
        }

        if (Notification.permission === "granted") {
          new Notification("Group List", {
            body: data.message,
            icon: "assets/icon-192.png",
            vibrate: [200, 100, 200],
            tag: 'group-list-alert'
          });
        }
      }
    } catch (err) { console.error(err); }
  };

  eventSource.onerror = function() {
    eventSource.close();
    eventSource = null;
    setTimeout(connectStream, 5000);
  };
}

connectStream();


// --- 3. SMART SUGGESTION LOGIC (Tags & Mentions) ---
function attachSuggest(inputId) {
  const input = document.getElementById(inputId);
  if (!input) return;
  
  const box = input.closest('.suggest');
  if (!box) return; 
  
  let list = box.querySelector('.suggest-list');
  if (!list) {
      list = document.createElement('div');
      list.className = 'suggest-list';
      box.appendChild(list);
  }

  let timer = null;
  let usersCache = null; // Cache users to avoid constant API calls

  function hide() { 
      list.style.display = 'none'; 
      list.innerHTML = ''; 
  }

  // Helper: Find the word currently being typed
  function getWordUnderCursor() {
      const val = input.value;
      const cursor = input.selectionStart;
      
      const left = val.slice(0, cursor);
      const lastSpace = left.lastIndexOf(' '); 
      const start = lastSpace + 1;
      const word = left.slice(start);
      
      return { word, start, end: cursor };
  }

  // Helper: Replace word with selection
  function replaceWord(newValue) {
      const val = input.value;
      const { start, end } = getWordUnderCursor();
      
      const before = val.slice(0, start);
      const after = val.slice(end);
      
      // Insert new value + space
      input.value = before + newValue + ' ' + after;
      
      // Move cursor
      const newCursorPos = before.length + newValue.length + 1;
      input.setSelectionRange(newCursorPos, newCursorPos);
      input.focus();
      hide();
  }

  // Listen for typing
  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const { word } = getWordUnderCursor();
      const q = word.trim();
      
      if (q.length < 1) { hide(); return; }

      // --- A. MENTION LOGIC (@username) ---
      if (q.startsWith('@')) {
          const query = q.slice(1).toLowerCase(); // Remove '@'

          // Fetch users if not cached
          if (!usersCache) {
              try {
                  const r = await fetch('api/get_users.php');
                  if (r.ok) {
                      usersCache = await r.json();
                  } else {
                      usersCache = [];
                  }
              } catch(e) { usersCache = []; }
          }

          // Filter users by name or username
          const matches = usersCache.filter(u => 
              u.username.toLowerCase().includes(query) || 
              u.display_name.toLowerCase().includes(query)
          );

          if (matches.length === 0) { hide(); return; }

          // Render User List
          list.innerHTML = matches.map(u => 
            `<div class="suggest-item user-item" data-val="@${u.username}" style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:bold;">${u.display_name}</span>
                <span style="color:#888; font-size:0.85em;">@${u.username}</span>
             </div>`
          ).join('');
          
          list.style.display = 'block';
          return;
      }

      // --- B. STANDARD SUGGEST (#Tags & History) ---
      // Uses existing suggest.php API
      try {
          const r = await fetch('api/suggest.php?q=' + encodeURIComponent(q), { cache: 'no-store' });
          if (!r.ok) { hide(); return; }
          
          const j = await r.json();
          const items = j.items || [];
          
          if (items.length === 0) { hide(); return; }

          list.innerHTML = items.map(t => 
            `<div class="suggest-item" data-val="${t.replaceAll('"', '&quot;')}">${t}</div>`
          ).join('');
          
          list.style.display = 'block';
      } catch(e) { hide(); }

    }, 200);
  });

  // Handle Clicks
  list.addEventListener('click', (e) => {
    const it = e.target.closest('.suggest-item');
    if (!it) return;
    
    e.preventDefault(); 
    e.stopPropagation();
    
    replaceWord(it.getAttribute('data-val'));
  });

  // Hide on blur/click away
  document.addEventListener('click', (e) => {
    if (!box.contains(e.target)) hide();
  });
}