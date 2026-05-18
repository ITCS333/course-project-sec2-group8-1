/*
  Requirement: Make the "Manage Weekly Breakdown" page interactive.

  Instructions:
  1. This file is already linked to `admin.html` via:
         <script src="admin.js" defer></script>

  2. In `admin.html`:
     - The form has id="week-form".
     - The submit button has id="add-week".
     - The <tbody> has id="weeks-tbody".
     - Columns rendered per row: Week Title | Start Date | Description | Actions.

  3. Implement the TODOs below.

  API base URL: ./api/index.php
  All requests and responses use JSON.
  Successful list response shape: { success: true, data: [ ...week objects ] }
  Each week object shape:
    {
      id:          number,   // integer primary key from the weeks table
      title:       string,
      start_date:  string,   // "YYYY-MM-DD"
      description: string,
      links:       string[]  // decoded array of URL strings
    }
*/

// --- Global Data Store ---
// Holds the weeks currently displayed in the table.
let weeks = [];

// --- Element Selections ---
const weekForm = document.getElementById("week-form");
const weeksTbody = document.getElementById("weeks-tbody");
const addWeekButton = document.getElementById("add-week");

// --- Functions ---
function createWeekRow(week) {
  const tr = document.createElement("tr");

  const titleTd = document.createElement("td");
  titleTd.textContent = week.title;
  tr.appendChild(titleTd);

  const dateTd = document.createElement("td");
  dateTd.textContent = week.start_date;
  tr.appendChild(dateTd);

  const descriptionTd = document.createElement("td");
  descriptionTd.textContent = week.description;
  tr.appendChild(descriptionTd);

  const actionsTd = document.createElement("td");
  const editButton = document.createElement("button");
  editButton.type = "button";
  editButton.className = "edit-btn";
  editButton.dataset.id = String(week.id);
  editButton.textContent = "Edit";

  const deleteButton = document.createElement("button");
  deleteButton.type = "button";
  deleteButton.className = "delete-btn";
  deleteButton.dataset.id = String(week.id);
  deleteButton.textContent = "Delete";

  actionsTd.appendChild(editButton);
  actionsTd.appendChild(deleteButton);
  tr.appendChild(actionsTd);

  return tr;
}

function renderTable() {
  if (!weeksTbody) {
    return;
  }

  weeksTbody.innerHTML = "";
  weeks.forEach((week) => {
    weeksTbody.appendChild(createWeekRow(week));
  });
}

function getFormFields() {
  const title = document.getElementById("week-title")?.value.trim() || "";
  const start_date = document.getElementById("week-start-date")?.value || "";
  const description = document.getElementById("week-description")?.value.trim() || "";
  const linksText = document.getElementById("week-links")?.value || "";
  const links = linksText
    .split("\n")
    .map((link) => link.trim())
    .filter((link) => link.length > 0);

  return { title, start_date, description, links };
}

async function handleAddWeek(event) {
  event.preventDefault();
  const fields = getFormFields();

  if (!addWeekButton) {
    return;
  }

  const editId = addWeekButton.dataset.editId;
  if (editId) {
    await handleUpdateWeek(Number(editId), fields);
    return;
  }

  const response = await fetch("./api/index.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(fields),
  });

  if (!response.ok) {
    return;
  }

  const result = await response.json();
  if (result.success) {
    weeks.push({ id: result.id, ...fields });
    renderTable();
    weekForm?.reset();
  }
}

async function handleUpdateWeek(id, fields) {
  const response = await fetch("./api/index.php", {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, ...fields }),
  });

  if (!response.ok) {
    return;
  }

  const result = await response.json();
  if (result.success) {
    const existingIndex = weeks.findIndex((week) => week.id === id);
    if (existingIndex !== -1) {
      weeks[existingIndex] = { id, ...fields };
    }
    renderTable();
    weekForm?.reset();
    addWeekButton.textContent = "Add Week";
    delete addWeekButton.dataset.editId;
  }
}

async function handleTableClick(event) {
  const target = event.target;
  if (!(target instanceof HTMLElement)) {
    return;
  }

  if (target.classList.contains("delete-btn")) {
    const id = Number(target.dataset.id);
    if (!Number.isInteger(id)) {
      return;
    }

    const response = await fetch(`./api/index.php?id=${id}`, {
      method: "DELETE",
    });

    if (!response.ok) {
      return;
    }

    const result = await response.json();
    if (result.success) {
      weeks = weeks.filter((week) => week.id !== id);
      renderTable();
    }
    return;
  }

  if (target.classList.contains("edit-btn")) {
    const id = Number(target.dataset.id);
    if (!Number.isInteger(id)) {
      return;
    }

    const week = weeks.find((item) => item.id === id);
    if (!week) {
      return;
    }

    document.getElementById("week-title").value = week.title;
    document.getElementById("week-start-date").value = week.start_date;
    document.getElementById("week-description").value = week.description;
    document.getElementById("week-links").value = (week.links || []).join("\n");

    addWeekButton.textContent = "Update Week";
    addWeekButton.dataset.editId = String(id);
  }
}

async function loadAndInitialize() {
  const response = await fetch("./api/index.php");
  if (!response.ok) {
    return;
  }

  const result = await response.json();
  if (result.success && Array.isArray(result.data)) {
    weeks = result.data;
  }

  renderTable();
  weekForm?.addEventListener("submit", handleAddWeek);
  weeksTbody?.addEventListener("click", handleTableClick);
}

// --- Initial Page Load ---
loadAndInitialize();
