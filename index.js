// index.js
const panRegex = /^\d{16}$/;

const form = document.querySelector("form");
const input = document.getElementById("pan");
const button = form.querySelector('button[type="submit"]');
const message = document.querySelector(".message");
const resultsBox = document.querySelector(".results");

// keep input digits-only and max 16
input.addEventListener("input", () => {
  let v = input.value.replace(/\D+/g, "").slice(0, 16);
  input.value = v;

  if (panRegex.test(v)) {
    button.disabled = false;
    button.style.cursor = "pointer";
    button.style.opacity = "1";
    message.textContent = "";
    message.removeAttribute("data-error");
  } else {
    button.disabled = true;
    button.style.cursor = "not-allowed";
    button.style.opacity = "0.7";
    message.textContent = v.length === 16 ? "❌ Invalid PAN (must be 16 digits)" : "";
    message.setAttribute("data-error", "true");
  }
});

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  const pan = input.value.trim();
  if (!panRegex.test(pan)) {
    message.textContent = "❌ Invalid PAN (must be 16 digits)";
    message.setAttribute("data-error", "true");
    return;
  }

  // loading state
  button.disabled = true;
  button.textContent = "Searching…";
  message.textContent = "";
  resultsBox.innerHTML = "";

  try {
    const fd = new FormData();
    fd.append("pan", pan);

    const res = await fetch("panLookup.php", { method: "POST", body: fd });
    const text = await res.text();
    let data;

    try { data = JSON.parse(text); } catch { throw new Error(text || "Bad JSON"); }

    if (!res.ok || data.ok === false) {
      throw new Error(data?.error || `HTTP ${res.status}`);
    }

    // show success
    message.textContent = "✅ Data received";
    message.removeAttribute("data-error");

    // simple render
    resultsBox.innerHTML = `
      <div class="card" style="padding:1rem;border:1px solid #cde7da">
        <h3 style="margin:.25rem 0;color:var(--accent)">Summary</h3>
        <ul style="margin:.5rem 0 0 1rem;line-height:1.6">
          ${Object.entries(data.summary).map(([k,v]) => `<li><strong>${k}</strong>: ${v}</li>`).join("")}
        </ul>
        <h3 style="margin:1rem 0 .25rem;color:var(--accent)">Sample</h3>
        <pre style="background:#f7fdf9;border:1px solid #cde7da;padding:.75rem;border-radius:8px;overflow:auto">${JSON.stringify(data.sample, null, 2)}</pre>
      </div>
    `;
  } catch (err) {
    message.textContent = "❌ " + err.message;
    message.setAttribute("data-error", "true");
  } finally {
    button.disabled = !panRegex.test(input.value);
    button.textContent = "Search";
    button.style.cursor = button.disabled ? "not-allowed" : "pointer";
    button.style.opacity = button.disabled ? "0.7" : "1";
  }
});
