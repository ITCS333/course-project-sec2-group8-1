/*
  Requirement: Populate the "Weekly Course Breakdown" list page.

  Instructions:
  1. This file is already linked to `list.html` via:
         <script src="list.js" defer></script>

  2. In `list.html`, the <section id="week-list-section"> is the container
     that this script populates.

  3. Implement the TODOs below.
*/

// --- Element Selections ---
const weekListSection = document.getElementById("week-list-section");

// --- Functions ---
function createWeekArticle(week) {
  const article = document.createElement("article");

  const title = document.createElement("h2");
  title.textContent = week.title;
  article.appendChild(title);

  const date = document.createElement("p");
  date.textContent = `Starts on: ${week.start_date}`;
  article.appendChild(date);

  const description = document.createElement("p");
  description.textContent = week.description;
  article.appendChild(description);

  const link = document.createElement("a");
  link.href = `details.html?id=${week.id}`;
  link.textContent = "View Details & Discussion";
  article.appendChild(link);

  return article;
}

async function loadWeeks() {
  const response = await fetch("./api/index.php");
  if (!response.ok) {
    return;
  }

  const result = await response.json();
  if (!weekListSection) {
    return;
  }

  weekListSection.innerHTML = "";

  if (result.success && Array.isArray(result.data)) {
    result.data.forEach((week) => {
      weekListSection.appendChild(createWeekArticle(week));
    });
  }
}

// --- Initial Page Load ---
loadWeeks();
