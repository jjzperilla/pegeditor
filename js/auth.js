(async function authCheck() {
  try {
    const res = await fetch("./api/ping.php");

    if (res.status === 401) {
      window.location.replace("./login.html");
    }
  } catch (e) {
    //console.error("Auth check failed", e);
    window.location.replace("./login.html");
  }
})();
