#include <WiFi.h>
#include <WebServer.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>

// ESP32 Access Point settings
const char* AP_SSID = "Classiee-Admin";
const char* AP_PASS = "";

// Your Railway app URL (replace with your actual Railway domain)
const char* RAILWAY_API = "https://your-app.railway.app/api_admin_teacher.php";

WebServer server(80);

// Session storage
String admin_session_id = "";
String admin_name = "";
int admin_id = 0;
bool is_logged_in = false;

// Global JSON buffers for cached data
String cached_teachers = "";
String cached_classes = "";

// HTTPS certificate verification (disable for testing, enable for production)
const char* root_ca = 
  "-----BEGIN CERTIFICATE-----\n"
  "MIIDdTCCAl2gAwIBAgILBAAAAAABFUtaw5QwDQYJKoZIhvcNAQEFBQAwVzELMAkG\n"
  "A1UEBhMCQkUxGTAXBgNVBAoTEEdsb2JhbFNpZ24gbnYtc2ExEDAOBgNVBAsTB1Jv\n"
  "b3QgQ0ExGzAZBgNVBAMTEkdsb2JhbFNpZ24gUm9vdCBDQTAeFw05ODA5MDExMjAw\n"
  "MDBaFw0yODAxMjgxMjAwMDBaMFcxCzAJBgNVBAYTAkJFMRkwFwYDVQQKExBHbG9i\n"
  "YWxTaWduIG52LXNhMRAwDgYDVQQLEwdSb290IENBMRswGQYDVQQDExJHbG9iYWxT\n"
  "aWduIFJvb3QgQ0EwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDaDuaZ\n"
  "jc6j40+kHvtQPN7kN/qC5zREDz51dAQ99gH4LgfhzCczn8YEJCWucDkqF+2gNlrG\n"
  "5aYMKIz8RlZp1yP0x6wTHGNhCPYZ8qYVVpePQJoLW3lUMOXXoNhS/7gkqXbKgXXz\n"
  "AkYN3LHIlxRg8eMXvzJzLAkJZBQXGcAEJCMIBfmX+VQ0oIdDVAUCabxBqE8L+qvJ\n"
  "Ah7S8N6vMHTVzQp0+qFMw5c3QJU8YMHGZuKSqZC4lxJ6Z3+xm9dJaZxVt/fZBCnl\n"
  "OwUnPGE3YvPSzO+GfRpqxYEUgHJM5k2b8jCa0Ll6AqIiG3P/hH8HgPbVDw+bJ2Ly\n"
  "OMHuhCfKGH/AgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNVHRMBAf8EBTAD\n"
  "AQH/MB0GA1UdDgQWBBR00eqKHnXqjxVaWzKR7uWL6Dp5/TANBgkqhkiG9w0BAQUF\n"
  "AAOCAQEA4Z4+wXvd/8EVPZSNqFqKb+7G90gM6m90pB5GFvv6rH+sNPtgLfKVTc2F\n"
  "KvvvDQ8qBYwI2K5uXvj5+MleFtSZFfnVz4kVEfXbFLd9qIl8ePJ0e5N8zIB9LXLr\n"
  "B1JKGEzDvFDNzQ5DnUaWbgBT6GkLkkQqL+eXFCdZdO3bHLJ1O0SH7iHlV6p9nZDR\n"
  "7DzXZRyHpFZf5dVWFgMWCUfJCt3Vj2V7AFRE9S4zcn0e0TS8h5UhzYB2lfmBvSu8\n"
  "VU9+5G3jqCHrxDqBN3LK7RBH9ZL6l5e6M/0e3Q9p8v7BT3HdP9aKKsQW4dNb0UqO\n"
  "fA==\n"
  "-----END CERTIFICATE-----\n";

// Make HTTPS API call
String makeAPICall(String endpoint, String method, String jsonPayload = "") {
  WiFiClientSecure client;
  client.setCACert(root_ca);
  HTTPClient https;
  
  String url = String(RAILWAY_API) + endpoint;
  
  if (https.begin(client, url)) {
    https.addHeader("Content-Type", "application/json");
    https.addHeader("Cookie", "PHPSESSID=" + admin_session_id);
    
    int httpCode;
    if (method == "POST") {
      httpCode = https.POST(jsonPayload);
    } else {
      httpCode = https.GET();
    }
    
    if (httpCode == HTTP_CODE_OK || httpCode == 200) {
      String payload = https.getString();
      https.end();
      return payload;
    }
    https.end();
  }
  return "";
}

// HTML/CSS/JS for admin panel
const char ADMIN_PAGE[] PROGMEM = R"rawliteral(
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Classiee Admin - ESP32</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background: linear-gradient(135deg, #0f172a, #1e293b);
      color: #0f172a;
    }
    .dashboard-wrapper {
      display: grid;
      grid-template-columns: 200px 1fr;
      min-height: 100vh;
    }
    .sidebar {
      background: #1e293b;
      color: white;
      padding: 20px;
      box-shadow: 2px 0 5px rgba(0,0,0,0.2);
    }
    .sidebar-header { margin-bottom: 20px; }
    .sidebar-logo { font-size: 24px; margin-right: 8px; }
    .sidebar h2 { margin: 0; font-size: 16px; }
    .nav-links { list-style: none; padding: 0; margin: 0; }
    .nav-links li { margin: 10px 0; }
    .nav-links a { color: #cbd5e1; text-decoration: none; }
    .nav-links a:hover { color: #0ea5e9; }
    .main-content {
      padding: 20px;
      overflow-y: auto;
      max-height: 100vh;
    }
    .top-bar { margin-bottom: 20px; }
    .top-bar h1 { margin: 0; font-size: 24px; color: white; }
    .card {
      background: white;
      border-radius: 12px;
      padding: 18px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      margin-bottom: 18px;
    }
    .card h3 { margin: 0 0 12px; font-size: 16px; }
    input, select, button {
      width: 100%;
      padding: 10px;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      font-size: 14px;
      margin-bottom: 8px;
    }
    button {
      background: #0ea5e9;
      color: white;
      border: none;
      cursor: pointer;
      font-weight: bold;
    }
    button:hover { background: #0284c7; }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    th, td { padding: 8px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    th { background: #f1f5f9; font-weight: bold; }
    .teacher-chip { display: inline-block; background: #0ea5e9; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; margin: 2px; }
    .error { color: #dc2626; background: #fee2e2; padding: 10px; border-radius: 6px; margin-bottom: 10px; }
    .success { color: #16a34a; background: #dcfce7; padding: 10px; border-radius: 6px; margin-bottom: 10px; }
    .hidden { display: none; }
    @media (max-width: 768px) {
      .dashboard-wrapper { grid-template-columns: 1fr; }
      .sidebar { display: none; }
    }
  </style>
</head>
<body>
  <div class="dashboard-wrapper">
    <nav class="sidebar">
      <div class="sidebar-header">
        <span class="sidebar-logo">🏫</span>
        <h2>Classiee</h2>
      </div>
      <ul class="nav-links">
        <li><a href="#" onclick="showSection('teachers')">👩‍🏫 Teachers</a></li>
        <li><a href="#" onclick="showSection('add-class')">➕ Add Classes</a></li>
        <li><a href="#" onclick="showSection('remove-class')">➖ Remove Classes</a></li>
        <li><a href="#" onclick="logout()">🚪 Logout</a></li>
      </ul>
    </nav>

    <main class="main-content">
      <header class="top-bar">
        <h1>Admin Panel</h1>
        <p id="welcomeMsg">Welcome, Admin!</p>
      </header>

      <div id="loginSection" class="card">
        <h3>Admin Login</h3>
        <div id="loginError" class="error hidden"></div>
        <input type="email" id="email" placeholder="Email" required>
        <input type="password" id="password" placeholder="Password" required>
        <button onclick="login()">Login</button>
      </div>

      <div id="teachersSection" class="card hidden">
        <h3>Teacher Classes Overview</h3>
        <table id="teachersTable">
          <thead><tr><th>Teacher</th><th>Assigned Classes</th></tr></thead>
          <tbody id="teachersList"></tbody>
        </table>
      </div>

      <div id="createTeacherSection" class="card hidden">
        <h3>Create Teacher</h3>
        <div id="createError" class="error hidden"></div>
        <div id="createSuccess" class="success hidden"></div>
        <input type="text" id="newName" placeholder="Full Name" required>
        <input type="email" id="newUsername" placeholder="Email" required>
        <input type="password" id="newPassword" placeholder="Password (min 6 chars)" required>
        <button onclick="createTeacher()">Create Teacher</button>
      </div>

      <div id="addClassSection" class="card hidden">
        <h3>Add Classes to Teacher</h3>
        <div id="addClassError" class="error hidden"></div>
        <select id="teacherSelect" required>
          <option value="">Select teacher...</option>
        </select>
        <div id="classCheckboxes" style="margin: 10px 0;"></div>
        <button onclick="addClasses()">Add Selected Classes</button>
      </div>

      <div id="removeClassSection" class="card hidden">
        <h3>Remove Class from Teacher</h3>
        <table id="removeTable">
          <thead><tr><th>Teacher</th><th>Class</th><th>Action</th></tr></thead>
          <tbody id="removeList"></tbody>
        </table>
      </div>
    </main>
  </div>

  <script>
    const API_BASE = window.location.origin + '/api';
    let teachersData = [];
    let classesData = [];
    let isLoggedIn = false;

    async function login() {
      const email = document.getElementById('email').value;
      const password = document.getElementById('password').value;
      const loginError = document.getElementById('loginError');
      
      const response = await fetch(API_BASE + '?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password })
      });
      
      const data = await response.json();
      if (data.success) {
        isLoggedIn = true;
        document.getElementById('loginSection').classList.add('hidden');
        document.getElementById('welcomeMsg').innerText = 'Welcome, ' + data.admin_name + '!';
        loadTeacherData();
        showSection('teachers');
      } else {
        loginError.innerText = data.error || 'Login failed';
        loginError.classList.remove('hidden');
      }
    }

    async function loadTeacherData() {
      const response = await fetch(API_BASE + '?action=get_teachers');
      const data = await response.json();
      if (data.success) {
        teachersData = data.teachers;
        classesData = data.classes;
        renderTeachers();
        renderClassCheckboxes();
        renderRemoveTable();
      }
    }

    function renderTeachers() {
      const tbody = document.getElementById('teachersList');
      tbody.innerHTML = '';
      teachersData.forEach(teacher => {
        const classes = (teacher.class || '').split(',').filter(c => c.trim());
        const classChips = classes.map(c => '<span class="teacher-chip">' + c.toUpperCase() + '</span>').join('');
        tbody.innerHTML += '<tr><td>' + teacher.name + '</td><td>' + (classChips || 'No classes') + '</td></tr>';
      });
    }

    function renderClassCheckboxes() {
      const container = document.getElementById('classCheckboxes');
      container.innerHTML = '';
      classesData.forEach(cls => {
        container.innerHTML += '<label><input type="checkbox" value="' + cls.class_code + '"> ' + cls.class_name + '</label><br>';
      });
    }

    function renderRemoveTable() {
      const tbody = document.getElementById('removeList');
      tbody.innerHTML = '';
      teachersData.forEach(teacher => {
        const classes = (teacher.class || '').split(',').filter(c => c.trim());
        classes.forEach(cls => {
          tbody.innerHTML += '<tr><td>' + teacher.name + '</td><td>' + cls.toUpperCase() + '</td><td><button onclick="removeClass(' + teacher.id + ', \'' + cls + '\')">Remove</button></td></tr>';
        });
      });
    }

    async function createTeacher() {
      const name = document.getElementById('newName').value;
      const username = document.getElementById('newUsername').value;
      const password = document.getElementById('newPassword').value;
      const errorDiv = document.getElementById('createError');
      const successDiv = document.getElementById('createSuccess');

      const response = await fetch(API_BASE + '?action=create_teacher', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, username, password })
      });

      const data = await response.json();
      if (data.success) {
        successDiv.innerText = 'Teacher created successfully!';
        successDiv.classList.remove('hidden');
        errorDiv.classList.add('hidden');
        document.getElementById('newName').value = '';
        document.getElementById('newUsername').value = '';
        document.getElementById('newPassword').value = '';
        loadTeacherData();
      } else {
        errorDiv.innerText = data.error;
        errorDiv.classList.remove('hidden');
      }
    }

    async function addClasses() {
      const teacherId = document.getElementById('teacherSelect').value;
      const selected = Array.from(document.querySelectorAll('#classCheckboxes input:checked')).map(x => x.value);
      const errorDiv = document.getElementById('addClassError');

      if (!teacherId || selected.length === 0) {
        errorDiv.innerText = 'Select a teacher and at least one class';
        errorDiv.classList.remove('hidden');
        return;
      }

      const response = await fetch(API_BASE + '?action=add_classes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ teacher_id: parseInt(teacherId), class_codes: selected })
      });

      const data = await response.json();
      if (data.success) {
        errorDiv.classList.add('hidden');
        loadTeacherData();
      } else {
        errorDiv.innerText = data.error;
        errorDiv.classList.remove('hidden');
      }
    }

    async function removeClass(teacherId, classCode) {
      const response = await fetch(API_BASE + '?action=remove_class', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ teacher_id: teacherId, class_code: classCode })
      });

      const data = await response.json();
      if (data.success) {
        loadTeacherData();
      }
    }

    async function logout() {
      await fetch(API_BASE + '?action=logout');
      isLoggedIn = false;
      document.getElementById('loginSection').classList.remove('hidden');
      document.getElementById('email').value = '';
      document.getElementById('password').value = '';
      showSection('login');
    }

    function showSection(section) {
      document.getElementById('teachersSection').classList.add('hidden');
      document.getElementById('createTeacherSection').classList.add('hidden');
      document.getElementById('addClassSection').classList.add('hidden');
      document.getElementById('removeClassSection').classList.add('hidden');

      if (section === 'teachers') document.getElementById('teachersSection').classList.remove('hidden');
      else if (section === 'add-class') document.getElementById('addClassSection').classList.remove('hidden');
      else if (section === 'remove-class') document.getElementById('removeClassSection').classList.remove('hidden');
    }
  </script>
</body>
</html>
)rawliteral";

void handleRoot() {
  server.send_P(200, "text/html", ADMIN_PAGE);
}

void handleAPI() {
  String action = server.arg("action");
  
  if (!is_logged_in && action != "login") {
    server.send(401, "application/json", "{\"error\":\"Unauthorized\"}");
    return;
  }

  String response = makeAPICall(String("?action=") + action, "GET");
  
  if (response != "") {
    server.send(200, "application/json", response);
  } else {
    server.send(500, "application/json", "{\"error\":\"API call failed\"}");
  }
}

void setup() {
  Serial.begin(115200);

  WiFi.mode(WIFI_AP);
  bool apStarted = WiFi.softAP(AP_SSID, AP_PASS);
  if (!apStarted) {
    Serial.println("Failed to start AP");
    return;
  }

  Serial.print("AP Name: ");
  Serial.println(AP_SSID);
  Serial.print("Open browser to: http://");
  Serial.println(WiFi.softAPIP());

  server.on("/", HTTP_GET, handleRoot);
  server.on("/api", HTTP_GET, handleAPI);
  server.on("/api", HTTP_POST, handleAPI);

  server.begin();
  Serial.println("HTTP server started");
}

void loop() {
  server.handleClient();
}
