(function () {
  var STORAGE_KEY = "classie_backend_base";
  var defaultBackend = "http://192.168.1.100/classie";

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
    registerForm.action = base + "/login_register.php";
    backendInput.value = base;
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

  document.querySelectorAll("a[data-show]").forEach(function (link) {
    link.addEventListener("click", function (e) {
      e.preventDefault();
      switchForm(link.getAttribute("data-show"));
    });
  });

  applyBackendTargets();
})();
