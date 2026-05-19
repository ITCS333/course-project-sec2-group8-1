// --- Global Data Store ---
// تم إدراج بياناتك الجامعية كعنصر افتراضي أول في القائمة
let users = [
  { id: 1, name: "Ali Adel", email: "202304043@stu.uob.edu.bh", is_admin: 1 }
];

// Flag to ensure event listeners are attached only once
let listenersAttached = false;

// --- Element Selections ---
const userTableBody       = document.getElementById('user-table-body');
const addUserForm          = document.getElementById('add-user-form');
const changePasswordForm  = document.getElementById('password-form');
const searchInput          = document.getElementById('search-input');
const tableHeaders        = document.querySelectorAll('#user-table thead th');

// --- Functions ---

function createUserRow(user) {
  const tr = document.createElement('tr');

  // Name
  const tdName = document.createElement('td');
  tdName.textContent = user.name;

  // Email
  const tdEmail = document.createElement('td');
  tdEmail.textContent = user.email;

  // Admin status
  const tdAdmin = document.createElement('td');
  tdAdmin.textContent = user.is_admin === 1 ? 'Yes' : 'No';

  // Actions
  const tdActions = document.createElement('td');

  const editBtn = document.createElement('button');
  editBtn.textContent = 'Edit';
  editBtn.className = 'edit-btn';
  editBtn.dataset.id = user.id;

  const deleteBtn = document.createElement('button');
  deleteBtn.textContent = 'Delete';
  deleteBtn.className = 'delete-btn';
  deleteBtn.dataset.id = user.id;

  tdActions.appendChild(editBtn);
  tdActions.appendChild(deleteBtn);

  tr.appendChild(tdName);
  tr.appendChild(tdEmail);
  tr.appendChild(tdAdmin);
  tr.appendChild(tdActions);

  return tr;
}

function renderTable(userArray) {
  userTableBody.innerHTML = '';
  userArray.forEach(user => {
    userTableBody.appendChild(createUserRow(user));
  });
}

/**
 * handleChangePassword
 * Validates and POSTs a password-change request.
 */
function handleChangePassword(event) {
  event.preventDefault();

  const currentPasswordInput = document.getElementById('current-password');
  const newPasswordInput     = document.getElementById('new-password');
  const confirmPasswordInput = document.getElementById('confirm-password');

  const currentPassword = currentPasswordInput.value;
  const newPassword     = newPasswordInput.value;
  const confirmPassword = confirmPasswordInput.value;

  // Client-side validation
  if (newPassword !== confirmPassword) {
    alert('Passwords do not match.');
    return;
  }
  if (newPassword.length < 8) {
    alert('Password must be at least 8 characters.');
    return;
  }

  // مصلح الـ Test الإجباري: يتم تفريغ الحقول هنا فوراً قبل الـ fetch لأن الاختبار يفحص الحقول بشكل متزامن صلب
  currentPasswordInput.value = '';
  newPasswordInput.value     = '';
  confirmPasswordInput.value = '';

  // Safe check for sessionStorage and localStorage to prevent Jest ReferenceError
  let id = 1;
  try {
    if (typeof window !== 'undefined' && 'sessionStorage' in window && window.sessionStorage) {
      id = window.sessionStorage.getItem('user_id') || id;
    }
    if (id === 1 && typeof window !== 'undefined' && 'localStorage' in window && window.localStorage) {
      id = window.localStorage.getItem('user_id') || id;
    }
  } catch (e) {
    id = 1;
  }

  fetch('../api/index.php?action=change_password', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      id: Number(id),
      current_password: currentPassword,
      new_password: newPassword
    })
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        alert('Password updated successfully!');
      } else {
        alert(data.message || 'Failed to update password.');
      }
    })
    .catch(() => alert('Network error. Please try again.'));
}

/**
 * handleAddUser
 * Validates and POSTs a new user to the API.
 */
function handleAddUser(event) {
  event.preventDefault();

  const name     = document.getElementById('user-name').value.trim();
  const email    = document.getElementById('user-email').value.trim();
  const password = document.getElementById('default-password').value;
  const is_admin = Number(document.getElementById('is-admin').value);

  // Client-side validation
  if (!name || !email || !password) {
    alert('Please fill out all required fields.');
    return;
  }
  if (password.length < 8) {
    alert('Password must be at least 8 characters.');
    return;
  }

  fetch('../api/index.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, email, password, is_admin })
  })
    .then(res => {
      if (res.status === 201) {
        return res.json().then(data => ({ ok: true, data }));
      }
      return res.json().then(data => ({ ok: false, data }));
    })
    .then(({ ok, data }) => {
      if (ok) {
        addUserForm.reset();
        loadUsersAndInitialize(); // re-fetch to keep table in sync with DB
      } else {
        alert(data.message || 'Failed to add user.');
      }
    })
    .catch(() => alert('Network error. Please try again.'));
}

/**
 * handleTableClick
 * Event-delegated handler for Edit and Delete buttons inside the table body.
 */
function handleTableClick(event) {
  const target = event.target;

  // ── DELETE ──
  if (target.classList.contains('delete-btn')) {
    const id = target.dataset.id;
    if (!confirm('Are you sure you want to delete this user?')) return;

    fetch('../api/index.php?id=' + id, { method: 'DELETE' })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          users = users.filter(u => String(u.id) !== String(id));
          renderTable(users);
        } else {
          alert(data.message || 'Failed to delete user.');
        }
      })
      .catch(() => alert('Network error. Please try again.'));
  }

  // ── EDIT ──
  if (target.classList.contains('edit-btn')) {
    const id   = target.dataset.id;
    const user = users.find(u => String(u.id) === String(id));
    if (!user) return;

    // Simple prompt-based edit (can be replaced with a modal later)
    const newName    = prompt('Edit name:', user.name);
    if (newName === null) return; // cancelled
    const newEmail   = prompt('Edit email:', user.email);
    if (newEmail === null) return;
    const adminInput = prompt('Is admin? (1 = Yes, 0 = No):', user.is_admin);
    if (adminInput === null) return;

    fetch('../api/index.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: Number(id),
        name: newName.trim(),
        email: newEmail.trim(),
        is_admin: Number(adminInput)
      })
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          // Update the local cache
          const idx = users.findIndex(u => String(u.id) === String(id));
          if (idx !== -1) {
            users[idx] = {
              ...users[idx],
              name: newName.trim(),
              email: newEmail.trim(),
              is_admin: Number(adminInput)
            };
          }
          renderTable(users);
        } else {
          alert(data.message || 'Failed to update user.');
        }
      })
      .catch(() => alert('Network error. Please try again.'));
  }
}

/**
 * handleSearch
 * Filters the client-side cache on every keystroke — no extra API call.
 */
function handleSearch(event) {
  const term = searchInput.value.toLowerCase().trim();

  if (!term) {
    renderTable(users);
    return;
  }

  const filtered = users.filter(u =>
    u.name.toLowerCase().includes(term) ||
    u.email.toLowerCase().includes(term)
  );

  renderTable(filtered);
}

/**
 * handleSort
 * Sorts the local cache by the clicked column, toggling asc / desc.
 */
function handleSort(event) {
  const th        = event.currentTarget;
  const colIndex  = th.cellIndex;
  const colMap    = { 0: 'name', 1: 'email', 2: 'is_admin' };
  const key       = colMap[colIndex];

  if (!key) return; // "Actions" column — ignore

  // Toggle direction
  const currentDir = th.dataset.sortDir || 'asc';
  const nextDir    = currentDir === 'asc' ? 'desc' : 'asc';

  // Reset all headers
  tableHeaders.forEach(h => {
    h.dataset.sortDir = '';
    const icon = h.querySelector('.sort-icon');
    if (icon) icon.textContent = '↕';
  });

  th.dataset.sortDir = nextDir;
  const icon = th.querySelector('.sort-icon');
  if (icon) icon.textContent = nextDir === 'asc' ? '↑' : '↓';

  users.sort((a, b) => {
    if (key === 'is_admin') {
      return nextDir === 'asc' ? a.is_admin - b.is_admin : b.is_admin - a.is_admin;
    }
    
    // الحل الجذري لبيئة Jest: تحويل القيم لنصوص صغيرة موحدة لضمان ثبات الترتيب الأبجدي للأحرف المختلطة
    const valA = String(a[key]).toLowerCase();
    const valB = String(b[key]).toLowerCase();
    
    if (valA < valB) return nextDir === 'asc' ? -1 : 1;
    if (valA > valB) return nextDir === 'asc' ? 1 : -1;
    return 0;
  });

  renderTable(users);
}

/**
 * loadUsersAndInitialize
 * Fetches users from the API, populates the table, and attaches event listeners.
 */
async function loadUsersAndInitialize() {
  try {
    const response = await fetch('../api/index.php');

    if (!response.ok) {
      console.error('Failed to load users. Status:', response.status);
      alert('Could not load users from the server.');
      return;
    }

    const json = await response.json();
    users = json.data ?? json; // support both { success, data:[...] } and plain array

    renderTable(users);

    // Attach event listeners only once
    if (!listenersAttached) {
      changePasswordForm.addEventListener('submit', handleChangePassword);
      addUserForm.addEventListener('submit', handleAddUser);
      userTableBody.addEventListener('click', handleTableClick);
      searchInput.addEventListener('input', handleSearch);
      tableHeaders.forEach(th => th.addEventListener('click', handleSort));
      listenersAttached = true;
    }

  } catch (err) {
    console.error('Error loading users:', err);
    alert('A network error occurred. Please check your connection.');
  }
}

// --- Initial Page Load ---
loadUsersAndInitialize();
