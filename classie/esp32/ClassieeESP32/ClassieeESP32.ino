#include <WiFi.h>
#include <WebServer.h>

// ESP32 Access Point settings.
const char* AP_SSID = "Classiee-ESP32";
const char* AP_PASS = "";

WebServer server(80);

const char LOGIN_PAGE[] PROGMEM = R"rawliteral(
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Classiee ESP32 Login</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      font-family: Arial, sans-serif;
      background: linear-gradient(135deg, #0f172a, #1e293b);
      color: #0f172a;
      padding: 16px;
    }
    .card {
      width: min(420px, 100%);
      background: #ffffff;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 18px 35px rgba(2, 6, 23, 0.35);
    }
    h1, h2 { margin: 0 0 8px; }
    .muted { margin: 0 0 14px; color: #64748b; font-size: 14px; }
    input, button {
      width: 100%;
      padding: 11px 12px;
      border-radius: 8px;
      font-size: 15px;
      margin-bottom: 10px;
    }
    input { border: 1px solid #cbd5e1; }
    button {
      border: none;
      background: #0ea5e9;
      color: #ffffff;
      font-weight: 700;
      cursor: pointer;
      margin-bottom: 0;
    }
  </style>
</head>
<body>
  <main class="card">
    <h1>Classiee</h1>
    <p class="muted">Student Login</p>
    <h2>Log In</h2>
    <form>
      <input type="email" placeholder="Email" required>
      <input type="password" placeholder="Password" required>
      <button type="submit">Log In</button>
    </form>
  </main>
</body>
</html>
)rawliteral";

void handleNotFound() {
  server.sendHeader("Location", "/");
  server.send(302, "text/plain", "");
}

void setup() {
  Serial.begin(115200);

  WiFi.mode(WIFI_AP);
  bool apStarted = strlen(AP_PASS) == 0 ? WiFi.softAP(AP_SSID) : WiFi.softAP(AP_SSID, AP_PASS);
  if (!apStarted) {
    Serial.println("Failed to start access point");
    return;
  }
  Serial.print("Wi-Fi name: ");
  Serial.println(AP_SSID);
  Serial.print("Open in browser: http://");
  Serial.println(WiFi.softAPIP());

  server.on("/", HTTP_GET, []() {
    server.send_P(200, "text/html", LOGIN_PAGE);
  });

  server.onNotFound(handleNotFound);
  server.begin();
  Serial.println("HTTP server started");
}

void loop() {
  server.handleClient();
}
