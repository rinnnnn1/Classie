#include <WiFi.h>
#include <WebServer.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>

// ESP32 Access Point settings
const char* AP_SSID = "Classiee-Admin";
const char* AP_PASS = "";
// Local Wi-Fi credentials for internet access
const char* STA_SSID = "GlobeAtHome_D8AF6";
const char* STA_PASS = "4E5285E1";
// Your Railway app base URL (replace with your actual Railway domain)
const char* RAILWAY_API_BASE = "https://classie-production-8178.up.railway.app";
const char* RAILWAY_ADMIN_API = "/api_admin_teacher.php";

WebServer server(80);

// Session storage
String admin_session_id = "";
String admin_name = "";
int admin_id = 0;
bool is_logged_in = false;
// Special sentinel to explicitly prevent default session forwarding
const String NO_SESSION_COOKIE = "#NOSESSION#";
// Support separate sessions for teacher and student when proxied through ESP32
String teacher_session_id = "";
String student_session_id = "";

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

  // URL-encode helper
  String urlencode(const String &str) {
    String encoded = "";
    char c;
    const char *s = str.c_str();
    for (size_t i = 0; i < str.length(); ++i) {
      c = s[i];
      if (('a' <= c && c <= 'z') || ('A' <= c && c <= 'Z') || ('0' <= c && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~') {
        encoded += c;
      } else if (c == ' ') {
        encoded += '+';
      } else {
        char buf[4];
        sprintf(buf, "%%%02X", (unsigned char)c);
        encoded += buf;
      }
    }
    return encoded;
  }

// Make HTTPS API call
String last_set_cookie = "";

String makeAPICall(String endpoint, String method, String jsonPayload = "", String cookieValue = "") {
  WiFiClientSecure client;
  client.setCACert(root_ca);
  HTTPClient https;

  String url;
  if (endpoint.startsWith("http://") || endpoint.startsWith("https://")) {
    url = endpoint;
  } else if (endpoint.startsWith("/")) {
    url = String(RAILWAY_API_BASE) + endpoint;
  } else {
    url = String(RAILWAY_API_BASE) + "/" + endpoint;
  }

  if (https.begin(client, url)) {
    // Default to JSON but allow callers to set form-encoded payload when needed
    https.addHeader("Content-Type", "application/json");
    // Add ESP32 proxy header to identify requests coming through this device
    https.addHeader("X-ESP32-Proxy", "true");
    if (cookieValue.length() > 0) {
      if (cookieValue != NO_SESSION_COOKIE) {
        https.addHeader("Cookie", "PHPSESSID=" + cookieValue);
      }
    } else if (admin_session_id.length() > 0) {
      https.addHeader("Cookie", "PHPSESSID=" + admin_session_id);
    }

    int httpCode;
    if (method == "POST") {
      httpCode = https.POST(jsonPayload);
    } else {
      httpCode = https.GET();
    }

    // capture Set-Cookie header if present
    last_set_cookie = https.header("Set-Cookie");

    if (httpCode == HTTP_CODE_OK || httpCode == 200 || httpCode == 302) {
      String payload = https.getString();
      https.end();
      return payload;
    }
    https.end();
  }
  return "";
}

// Forward declarations for helpers used below
void extractOptionsFromSelect(const String &html, const String &selectId, String &outJson);
void extractRadioClassOptions(const String &html, String &outJson);

// HTML/CSS/JS for admin panel
const char ADMIN_PAGE[] PROGMEM = R"rawliteral(
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Classiee Login - ESP32</title>
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
        <h1>Classiee Panel</h1>
        <p id="welcomeMsg">Welcome!</p>
      </header>

      <div id="loginSection" class="card">
        <h3>Login</h3>
        <p style="margin:0 0 12px;color:#475569;">Use your Classiee account and select your role.</p>
        <div id="loginError" class="error hidden"></div>
        <label for="roleSelect">Login as</label>
        <select id="roleSelect">
          <option value="admin">Admin</option>
          <option value="teacher">Teacher</option>
          <option value="student">Student</option>
        </select>
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
      const email = document.getElementById('email').value.trim();
      const password = document.getElementById('password').value;
      const loginError = document.getElementById('loginError');
      loginError.classList.add('hidden');
      loginError.innerText = '';

      if (!email || !password) {
        loginError.innerText = 'Email and password are required.';
        loginError.classList.remove('hidden');
        return;
      }

      const role = document.getElementById('roleSelect').value || 'admin';
      const response = await fetch(API_BASE + '?action=login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, password, role })
        });
      
      let data;
      try {
        data = await response.json();
      } catch (err) {
        loginError.innerText = 'Server returned invalid response.';
        loginError.classList.remove('hidden');
        return;
      }

      if (data.success) {
        isLoggedIn = true;
        document.getElementById('loginSection').classList.add('hidden');
        document.getElementById('welcomeMsg').innerText = 'Welcome, ' + (data.role || role) + '!';
        if (role === 'admin') {
          loadTeacherData();
          showSection('teachers');
        } else {
          document.getElementById('loginSection').classList.add('hidden');
          // Teacher/student login succeeded and session is stored on the ESP32 proxy.
          const msg = document.createElement('div');
          msg.className = 'success';
          msg.innerText = 'Logged in as ' + (data.role || role) + '. You can now use the proxy features.';
          document.querySelector('.main-content').prepend(msg);
        }
      } else {
        loginError.innerText = data.error || 'Login failed.';
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
  // Redirect to deployed Railway web app instead of serving the local UI
  server.sendHeader("Location", String(RAILWAY_API_BASE));
  server.send(302, "text/plain", "");
}

void handleAPI() {
  String action = server.arg("action");
  // Allow login and role-specific actions without prior admin session
  bool isTeacherAction = action.startsWith("teacher_");
  bool isStudentAction = action.startsWith("student_");
  if (!is_logged_in && !(action == "login" || isTeacherAction || isStudentAction)) {
    server.send(401, "application/json", "{\"error\":\"Unauthorized\"}");
    return;
  }

  // Handle POST login requests with role
  if (action == "login" && server.method() == HTTP_POST) {
    String body = server.arg("plain");
    // parse simple JSON payload for email, password, role
    DynamicJsonDocument doc(512);
    DeserializationError err = deserializeJson(doc, body);
    String email = "";
    String password = "";
    String role = "admin";
    if (!err) {
      email = doc["email"].as<String>();
      password = doc["password"].as<String>();
      role = doc["role"].as<String>();
    }

    if (role == "admin") {
      // admin login via api_admin_teacher.php expects JSON
      String payload = body;
      String resp = makeAPICall(String(RAILWAY_ADMIN_API) + "?action=login", "POST", payload, "");
      if (resp != "") {
        // if success, mark logged in and keep admin session if Set-Cookie provided
        if (last_set_cookie.length() > 0 && last_set_cookie.indexOf("PHPSESSID=") >= 0) {
          int p = last_set_cookie.indexOf("PHPSESSID=") + 10;
          int e = last_set_cookie.indexOf(';', p);
          String sid = (e > p) ? last_set_cookie.substring(p, e) : last_set_cookie.substring(p);
          admin_session_id = sid;
        }
        // forward backend response
        server.send(200, "application/json", resp);
        // set logged-in flag if login succeeded (frontend checks JSON)
        is_logged_in = true;
      } else {
        server.send(401, "application/json", "{\"error\":\"Login failed\"}");
      }
      return;
    }

    // Teacher or Student login: use login_register.php JSON API
    if (role == "teacher" || role == "student") {
      String loginUrl = String(RAILWAY_API_BASE) + "/login_register.php";

      DynamicJsonDocument payloadDoc(256);
      payloadDoc["email"] = email;
      payloadDoc["password"] = password;
      payloadDoc["role"] = role;
      String payload;
      serializeJson(payloadDoc, payload);

      String respBody = makeAPICall(loginUrl, "POST", payload, NO_SESSION_COOKIE);
      if (respBody.length() > 0) {
        DynamicJsonDocument respDoc(512);
        DeserializationError err = deserializeJson(respDoc, respBody);
        String respRole = respDoc["role"].as<String>();
        if (!err && respDoc["success"] == true && respRole == role) {
          if (last_set_cookie.length() > 0 && last_set_cookie.indexOf("PHPSESSID=") >= 0) {
            int p = last_set_cookie.indexOf("PHPSESSID=") + 10;
            int e = last_set_cookie.indexOf(';', p);
            String sid = (e > p) ? last_set_cookie.substring(p, e) : last_set_cookie.substring(p);
            if (role == "teacher") teacher_session_id = sid;
            if (role == "student") student_session_id = sid;
          }
          server.send(200, "application/json", respBody);
          return;
        }
      }
      server.send(401, "application/json", "{\"error\":\"Invalid credentials\"}");
      return;
    }
  }

  // Teacher-specific endpoints (proxy and parse HTML)
  if (action == "teacher_get_classes") {
    if (teacher_session_id.length() == 0) {
      server.send(401, "application/json", "{\"error\":\"No teacher session\"}");
      return;
    }
    String url = String(RAILWAY_API_BASE) + "/teacher.php";
    String html = makeAPICall(url, "GET", "", teacher_session_id);
    String classesJson;
    extractOptionsFromSelect(html, "class-select", classesJson);
    server.send(200, "application/json", classesJson);
    return;
  }

  if (action == "teacher_toggle" && server.method() == HTTP_POST) {
    if (teacher_session_id.length() == 0) {
      server.send(401, "application/json", "{\"error\":\"No teacher session\"}");
      return;
    }
    String body = server.arg("plain");
    DynamicJsonDocument doc(512);
    DeserializationError err = deserializeJson(doc, body);
    if (err) {
      server.send(400, "application/json", "{\"error\":\"Invalid JSON\"}");
      return;
    }
    String classCode = doc["class"].as<String>();
    int newState = doc["new_state"].as<int>();
    if (classCode.length() == 0) {
      server.send(400, "application/json", "{\"error\":\"Missing class\"}");
      return;
    }
    String url = String(RAILWAY_API_BASE) + "/teacher.php";

    // build form data
    String form = "class=" + urlencode(classCode) + "&new_state=" + String(newState) + "&history_days=7&toggle_attendance=1";
    WiFiClientSecure client;
    client.setCACert(root_ca);
    HTTPClient https;
    String respBody = "";
    int httpCode = 0;
    if (https.begin(client, url)) {
      https.addHeader("Content-Type", "application/x-www-form-urlencoded");
      https.addHeader("X-ESP32-Proxy", "true");
      https.addHeader("Cookie", "PHPSESSID=" + teacher_session_id);
      httpCode = https.POST(form);
      respBody = https.getString();
      https.end();
    }
    if (httpCode > 0) {
      server.send(200, "application/json", "{\"success\":true}");
    } else {
      server.send(500, "application/json", "{\"error\":\"Request failed\"}");
    }
    return;
  }

  // Student-specific endpoints
  if (action == "student_get_classes") {
    if (student_session_id.length() == 0) {
      server.send(401, "application/json", "{\"error\":\"No student session\"}");
      return;
    }
    String url = String(RAILWAY_API_BASE) + "/select_class.php";
    String html = makeAPICall(url, "GET", "", student_session_id);
    String classesJson;
    extractRadioClassOptions(html, classesJson);
    server.send(200, "application/json", classesJson);
    return;
  }

  if (action == "student_mark_attendance" && server.method() == HTTP_POST) {
    if (student_session_id.length() == 0) {
      server.send(401, "application/json", "{\"error\":\"No student session\"}");
      return;
    }
    String body = server.arg("plain");
    DynamicJsonDocument doc(512);
    DeserializationError err = deserializeJson(doc, body);
    if (err) {
      server.send(400, "application/json", "{\"error\":\"Invalid JSON\"}");
      return;
    }
    String classCode = doc["class"].as<String>();
    if (classCode.length() == 0) {
      server.send(400, "application/json", "{\"error\":\"Missing class\"}");
      return;
    }
    String url = String(RAILWAY_API_BASE) + "/attendance.php";

    String form = "class=" + urlencode(classCode);
    WiFiClientSecure client;
    client.setCACert(root_ca);
    HTTPClient https;
    String respBody = "";
    int httpCode = 0;
    if (https.begin(client, url)) {
      https.addHeader("Content-Type", "application/x-www-form-urlencoded");
      https.addHeader("X-Requested-With", "XMLHttpRequest");
      https.addHeader("X-ESP32-Proxy", "true");
      https.addHeader("Cookie", "PHPSESSID=" + student_session_id);
      httpCode = https.POST(form);
      if (httpCode > 0) respBody = https.getString();
      https.end();
    }
    if (httpCode > 0) {
      // forward JSON response from attendance.php
      server.send(200, "application/json", respBody.length() ? respBody : "{\"success\":true}");
    } else {
      server.send(500, "application/json", "{\"error\":\"Request failed\"}");
    }
    return;
  }

  // Default: proxy GET actions to admin API
  String response = makeAPICall(String(RAILWAY_ADMIN_API) + "?action=" + action, "GET");
  
  if (response != "") {
    server.send(200, "application/json", response);
  } else {
    server.send(500, "application/json", "{\"error\":\"API call failed\"}");
  }
}

// Helper: extract <option value="code">name</option> within a select block
void extractOptionsFromSelect(const String &html, const String &selectId, String &outJson) {
  outJson = "[]";
  int s = html.indexOf("<select id=\"" + selectId + "\"");
  if (s < 0) return;
  int open = html.indexOf('>', s);
  if (open < 0) return;
  int close = html.indexOf("</select>", open);
  if (close < 0) return;
  String inner = html.substring(open + 1, close);
  // find option tags
  DynamicJsonDocument doc(1024);
  JsonArray arr = doc.to<JsonArray>();
  int idx = 0;
  while (true) {
    int vpos = inner.indexOf("value=\"");
    if (vpos < 0) break;
    int valStart = vpos + 7;
    int valEnd = inner.indexOf('"', valStart);
    if (valEnd < 0) break;
    String val = inner.substring(valStart, valEnd);
    int tagClose = inner.indexOf('>', valEnd);
    if (tagClose < 0) break;
    int optClose = inner.indexOf("</option>", tagClose);
    if (optClose < 0) break;
    String text = inner.substring(tagClose + 1, optClose);
    // trim
    text.trim();
    JsonObject obj = arr.createNestedObject();
    obj["code"] = val;
    obj["name"] = text;
    inner = inner.substring(optClose + 9);
    idx++;
  }
  String out;
  serializeJson(arr, out);
  outJson = out;
}

// Helper: extract radio inputs in select_class.php (class options)
void extractRadioClassOptions(const String &html, String &outJson) {
  outJson = "[]";
  DynamicJsonDocument doc(2048);
  JsonArray arr = doc.to<JsonArray>();
  int pos = 0;
  while (true) {
    int inPos = html.indexOf("input type=\"radio\"", pos);
    if (inPos < 0) break;
    int valPos = html.indexOf("value=\"", inPos);
    if (valPos < 0) break;
    int vStart = valPos + 7;
    int vEnd = html.indexOf('"', vStart);
    if (vEnd < 0) break;
    String val = html.substring(vStart, vEnd);
    // find the class-option-main span after this input
    int mainPos = html.indexOf("class-option-main", vEnd);
    if (mainPos < 0) break;
    int tagStart = html.indexOf('>', mainPos);
    int tagEnd = html.indexOf("</span>", tagStart);
    if (tagStart < 0 || tagEnd < 0) break;
    String name = html.substring(tagStart + 1, tagEnd);
    name.trim();
    JsonObject obj = arr.createNestedObject();
    obj["code"] = val;
    obj["name"] = name;
    pos = tagEnd + 7;
  }
  String out;
  serializeJson(arr, out);
  outJson = out;
}

void setup() {
Serial.begin(115200);
delay(50);
Serial.println("BOOT START");

  WiFi.mode(WIFI_AP_STA);
  bool apStarted = WiFi.softAP(AP_SSID, AP_PASS);
  if (!apStarted) {
    Serial.println("Failed to start AP");
  } else {
    Serial.print("AP Name: ");
    Serial.println(AP_SSID);
    Serial.print("AP IP: ");
    Serial.println(WiFi.softAPIP());
  }

  WiFi.begin(STA_SSID, STA_PASS);
  Serial.print("Connecting to Wi-Fi");
  int connectAttempts = 0;
  while (WiFi.status() != WL_CONNECTED && connectAttempts < 30) {
    delay(500);
    Serial.print(".");
    connectAttempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println();
    Serial.print("Connected to Wi-Fi. STA IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println();
    Serial.println("Failed to connect to Wi-Fi. Check STA_SSID and STA_PASS.");
  }

  server.on("/", HTTP_GET, handleRoot);
  server.on("/api", HTTP_GET, handleAPI);
  server.on("/api", HTTP_POST, handleAPI);

  server.begin();
  Serial.println("HTTP server started");
}

void loop() {
  server.handleClient();
}
