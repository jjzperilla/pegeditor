async function login() {
  const password = document.getElementById("password").value;

  const res = await fetch("./api/login.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ password })
  });

  const data = await res.json();

  if (data.status === "success") {
    window.location.href = "./index.html";
  } else {
    alert("Invalid password");
  }
}

// Enter key support
document.addEventListener("keydown", e => {
  if (e.key === "Enter") login();
});
