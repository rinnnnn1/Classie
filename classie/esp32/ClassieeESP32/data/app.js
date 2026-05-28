(function () {
  var STORAGE_KEY = "classie_backend_base";
var defaultBackend = "https://classie-production-8178.up.railway.app";

  var backendInput = document.getElementById("backendBase");
  var saveBtn = document.getElementById("saveBackend");
  var loginForm = document.getElementById("loginPost");
  var registerForm = document.getElementById("registerPost");

  function getBackendBase() {
    var saved = localStorage.getItem(STORAGE_KEY);
    return (saved && saved.trim()) ? saved.trim().replace(/\/$/, "") : defaultBackend;
  }

  function applyBackendTargets() {
    var base = getBackendBase();
    loginForm.action = base + "/login_register.php";
    if (registerForm) {
      registerForm.action = base + "/login_register.php";
    }
    backendInput.value = base;
  }

  function showStatus(message, isError) {
    var status = document.getElementById('loginStatus');
    if (!status) return;
    status.textContent = message || '';
    status.style.color = isError ? '#dc2626' : '#16a34a';
  }

  function switchForm(idToShow) {
    document.querySelectorAll(".form-card").forEach(function (card) {
      card.classList.remove("active");
    });
    document.getElementById(idToShow).classList.add("active");
  }

  saveBtn.addEventListener("click", function () {
    var val = (backendInput.value || "").trim().replace(/\/$/, "");
    if (!val) {
      alert("Please enter your backend URL first.");
      return;
    }
    localStorage.setItem(STORAGE_KEY, val);
    applyBackendTargets();
    alert("Backend URL saved.");
  });

  loginForm.addEventListener('submit', function (event) {
    event.preventDefault();
    showStatus('', false);
    var base = getBackendBase();
    var email = loginForm.querySelector('input[name="email"]').value.trim();
    var password = loginForm.querySelector('input[name="password"]').value;

    if (!email || !password) {
      showStatus('Email and password are required.', true);
      return;
    }

    fetch(base + '/login_register.php', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-ESP32-Proxy': 'true'
      },
      body: JSON.stringify({
        email: email,
        password: password,
        role: 'student'
      })
    })
    .then(function (response) {
      return response.json().then(function (data) {
        return {status: response.status, data: data};
      });
    })
    .then(function (result) {
      if (result.status === 200 && result.data.success) {
        window.location.href = base + '/' + result.data.redirect;
      } else {
        showStatus(result.data.error || 'Login failed.', true);
      }
    })
    .catch(function (err) {
      showStatus('Unable to reach backend: ' + err.message, true);
    });
  });

  document.querySelectorAll("a[data-show]").forEach(function (link) {
    link.addEventListener("click", function (e) {
      e.preventDefault();
      switchForm(link.getAttribute("data-show"));
    });
  });

  applyBackendTargets();
})();
