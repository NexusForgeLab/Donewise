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
      new Notification("Group List", { body: "Instant Alerts Enabled!", icon: "/assets/icon-192.png" });
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
  if (eventSource) return; // Already connected

  // Connect to the PHP stream
  eventSource = new EventSource("/api/stream.php");

  eventSource.onmessage = function(e) {
    try {
      const data = JSON.parse(e.data);
      
      if (data.type === 'notification') {
        // 1. Update Badge
        const badge = document.getElementById('unreadBadge');
        if (badge) {
          badge.textContent = data.unread;
          badge.style.display = (data.unread > 0) ? '' : 'none';
        }

        // 2. Trigger System Notification
        if (Notification.permission === "granted") {
          new Notification("Group List", {
            body: data.message,
            icon: "/assets/icon-192.png",
            vibrate: [200, 100, 200],
            tag: 'group-list-alert' // Prevents stacking too many
          });
        }
      }
    } catch (err) { console.error(err); }
  };

  eventSource.onerror = function() {
    // If connection dies, try to reconnect in 5 seconds
    eventSource.close();
    eventSource = null;
    setTimeout(connectStream, 5000);
  };
}

// Start the connection
connectStream();


// --- 3. SMART SUGGESTION LOGIC (Tags & Items) ---
function attachSuggest(inputId) {
  const input = document.getElementById(inputId);
  if (!input) return;
  
  // Find the container
  const box = input.closest('.suggest');
  if (!box) return; 
  
  // Create list if missing
  let list = box.querySelector('.suggest-list');
  if (!list) {
      list = document.createElement('div');
      list.className = 'suggest-list';
      box.appendChild(list);
  }

  let timer = null;

  function hide() { 
      list.style.display = 'none'; 
      list.innerHTML = ''; 
  }

  // Helper: Find the word currently being typed (handles "Milk #ur")
  function getWordUnderCursor() {
      const val = input.value;
      const cursor = input.selectionStart;
      
      // Get text before cursor
      const left = val.slice(0, cursor);
      
      // Find the last space before the cursor to identify start of current word
      const lastSpace = left.lastIndexOf(' '); 
      const start = lastSpace + 1;
      
      // The word is everything from that space to the cursor
      const word = left.slice(start);
      
      return { word, start, end: cursor };
  }

  // Helper: Replace just the current word with the selected suggestion
  function replaceWord(newValue) {
      const val = input.value;
      const { start, end } = getWordUnderCursor();
      
      const before = val.slice(0, start);
      const after = val.slice(end);
      
      // Insert new value and add a space
      input.value = before + newValue + ' ' + after;
      
      // Move cursor to end of inserted word
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
      
      // Don't search for empty strings
      if (q.length < 1) { hide(); return; }

      // Fetch suggestions (API handles both Item History and #Tags)
      const r = await fetch('/api/suggest.php?q=' + encodeURIComponent(q), { cache: 'no-store' });
      if (!r.ok) { hide(); return; }
      
      const j = await r.json();
      const items = j.items || [];
      
      if (items.length === 0) { hide(); return; }

      // Render items
      list.innerHTML = items.map(t => 
        `<div class="suggest-item" data-val="${t.replaceAll('"', '&quot;')}">${t}</div>`
      ).join('');
      
      list.style.display = 'block';
    }, 200);
  });

  // Listen for selection click
  list.addEventListener('click', (e) => {
    const it = e.target.closest('.suggest-item');
    if (!it) return;
    
    // Prevent button submission if inside a form
    e.preventDefault(); 
    e.stopPropagation();
    
    replaceWord(it.getAttribute('data-val'));
  });

  // Hide when clicking away
  document.addEventListener('click', (e) => {
    if (!box.contains(e.target)) hide();
  });
}