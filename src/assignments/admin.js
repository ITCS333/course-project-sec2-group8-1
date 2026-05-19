// --- Global Data Store ---
// Holds the assignments currently displayed in the table.
let assignments = [];

// --- Element Selections ---
// TODO: Select the assignment form by id 'assignment-form'.
const assignmentForm = document.getElementById("assignment-form");

// TODO: Select the assignments table body by id 'assignments-tbody'.
const assignmentsTbody = document.getElementById("assignments-tbody");

// --- Functions ---

/**
 * TODO: Implement createAssignmentRow.
 *
 * Parameters:
 *   assignment — one assignment object with shape:
 *     { id, title, due_date, description, files }
 *
 * Returns a <tr> element with four <td>s:
 *   1. title
 *   2. due_date   (the "YYYY-MM-DD" string — use due_date, not dueDate)
 *   3. description
 *   4. Actions — two buttons:
 *        <button class="edit-btn"   data-id="{id}">Edit</button>
 *        <button class="delete-btn" data-id="{id}">Delete</button>
 *      The data-id holds the integer primary key from the assignments table.
 */
function createAssignmentRow(assignment) {
  const tr = document.createElement("tr");

  // Title Column
  const tdTitle = document.createElement("td");
  tdTitle.textContent = assignment.title;
  tr.appendChild(tdTitle);

  // Due Date Column
  const tdDueDate = document.createElement("td");
  tdDueDate.textContent = assignment.due_date;
  tr.appendChild(tdDueDate);

  // Description Column
  const tdDescription = document.createElement("td");
  tdDescription.textContent = assignment.description;
  tr.appendChild(tdDescription);

  // Actions Column
  const tdActions = document.createElement("td");
  
  const editBtn = document.createElement("button");
  editBtn.className = "edit-btn";
  editBtn.setAttribute("data-id", assignment.id);
  editBtn.textContent = "Edit";
  
  const deleteBtn = document.createElement("button");
  deleteBtn.className = "delete-btn";
  deleteBtn.setAttribute("data-id", assignment.id);
  deleteBtn.textContent = "Delete";
  
  // Custom PicoCSS styling adjustments to align buttons nicely (optional but practical)
  editBtn.style.marginRight = "0.5rem";
  editBtn.style.display = "inline-block";
  deleteBtn.style.display = "inline-block";
  deleteBtn.classList.add("secondary"); // Visual distinction for delete button

  tdActions.appendChild(editBtn);
  tdActions.appendChild(deleteBtn);
  tr.appendChild(tdActions);

  return tr;
}

/**
 * TODO: Implement renderTable.
 *
 * It should:
 * 1. Clear the assignments table body (set innerHTML to "").
 * 2. Loop through the global `assignments` array.
 * 3. For each assignment, call createAssignmentRow(assignment) and
 *    append the <tr> to the table body.
 */
function renderTable() {
  if (assignmentsTbody) {
    assignmentsTbody.innerHTML = "";
    assignments.forEach(assignment => {
      const row = createAssignmentRow(assignment);
      assignmentsTbody.appendChild(row);
    });
  }
}

/**
 * TODO: Implement handleAddAssignment (async).
 *
 * This is the event handler for the form's 'submit' event.
 * It should:
 * 1. Call event.preventDefault().
 * 2. Read values from:
 *       - #assignment-title        → title (string)
 *       - #assignment-due-date     → due_date (string, "YYYY-MM-DD")
 *       - #assignment-description → description (string)
 *       - #assignment-files        → split by newlines (\n) and filter
 *                                    empty strings to produce a files array.
 * 3. Check if the submit button (#add-assignment) has a data-edit-id
 *    attribute.
 *    - If it does, call handleUpdateAssignment() with that id and the
 *      field values.
 *    - If it does not, send a POST to './api/index.php' with the body:
 *        { title, due_date, description, files }
 *      On success (result.success === true):
 *        - Add the new assignment (with the id from result.id) to the
 *          global `assignments` array.
 *        - Call renderTable().
 *        - Reset the form.
 */
async function handleAddAssignment(event) {
  event.preventDefault();

  const title = document.getElementById("assignment-title").value.trim();
  const due_date = document.getElementById("assignment-due-date").value;
  const description = document.getElementById("assignment-description").value.trim();
  
  const filesRaw = document.getElementById("assignment-files").value;
  const files = filesRaw.split("\n").map(url => url.trim()).filter(url => url !== "");

  const submitBtn = document.getElementById("add-assignment");
  const editId = submitBtn.getAttribute("data-edit-id");

  const fields = { title, due_date, description, files };

  if (editId) {
    await handleUpdateAssignment(parseInt(editId, 10), fields);
  } else {
    try {
      const response = await fetch("./api/index.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify(fields)
      });
      const result = await response.json();

      if (result.success) {
        // Construct the new object combining the server dynamic ID and field data
        const newAssignment = { id: result.id, ...fields };
        assignments.push(newAssignment);
        renderTable();
        assignmentForm.reset();
      } else {
        alert("Failed to add assignment: " + (result.message || "Unknown error"));
      }
    } catch (error) {
      console.error("Error adding assignment:", error);
    }
  }
}

/**
 * TODO: Implement handleUpdateAssignment (async).
 *
 * Parameters:
 *   id     — the integer primary key of the assignment being edited.
 *   fields — object with { title, due_date, description, files }.
 *
 * It should:
 * 1. Send a PUT to './api/index.php' with the body:
 *       { id, title, due_date, description, files }
 * 2. On success:
 *    - Update the matching entry in the global `assignments` array.
 *    - Call renderTable().
 *    - Reset the form.
 *    - Restore the submit button text to "Add Assignment" and remove
 *      its data-edit-id attribute.
 */
async function handleUpdateAssignment(id, fields) {
  try {
    const response = await fetch("./api/index.php", {
      method: "PUT",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ id, ...fields })
    });
    const result = await response.json();

    if (result.success) {
      // Find index and local updates
      const idx = assignments.findIndex(item => item.id === id);
      if (idx !== -1) {
        assignments[idx] = { id, ...fields };
      }

      renderTable();
      assignmentForm.reset();

      // Restore submit button state
      const submitBtn = document.getElementById("add-assignment");
      submitBtn.textContent = "Add Assignment";
      submitBtn.removeAttribute("data-edit-id");
    } else {
      alert("Failed to update assignment: " + (result.message || "Unknown error"));
    }
  } catch (error) {
    console.error("Error updating assignment:", error);
  }
}

/**
 * TODO: Implement handleTableClick (async).
 *
 * This is a delegated click listener on the assignments table body.
 * It should:
 * 1. If event.target has class "delete-btn":
 *    a. Read the integer id from event.target.dataset.id.
 *    b. Send a DELETE to './api/index.php?id=<id>'.
 *    c. On success, remove the assignment from the global `assignments`
 *       array and call renderTable().
 *
 * 2. If event.target has class "edit-btn":
 *    a. Read the integer id from event.target.dataset.id.
 *    b. Find the matching assignment in the global `assignments` array.
 *    c. Populate the form fields:
 *         #assignment-title        ← assignment.title
 *         #assignment-due-date    ← assignment.due_date
 *         #assignment-description ← assignment.description
 *         #assignment-files        ← assignment.files joined with newlines (\n)
 *    d. Change the submit button (#add-assignment) text to
 *       "Update Assignment" and set its data-edit-id attribute to the
 *       assignment's id.
 */
async function handleTableClick(event) {
  const target = event.target;
  const id = parseInt(target.getAttribute("data-id"), 10);

  if (!id) return; // Prevent executing if clicking empty row space

  // 1. Delete Flow
  if (target.classList.contains("delete-btn")) {
    if (!confirm("Are you sure you want to delete this assignment?")) return;

    try {
      const response = await fetch(`./api/index.php?id=${id}`, {
        method: "DELETE"
      });
      const result = await response.json();

      if (result.success) {
        assignments = assignments.filter(item => item.id !== id);
        renderTable();
      } else {
        alert("Failed to delete assignment: " + (result.message || "Unknown error"));
      }
    } catch (error) {
      console.error("Error deleting assignment:", error);
    }
  }

  // 2. Edit Flow
  if (target.classList.contains("edit-btn")) {
    const assignment = assignments.find(item => item.id === id);
    
    if (assignment) {
      document.getElementById("assignment-title").value = assignment.title;
      document.getElementById("assignment-due-date").value = assignment.due_date;
      document.getElementById("assignment-description").value = assignment.description;
      document.getElementById("assignment-files").value = assignment.files ? assignment.files.join("\n") : "";

      const submitBtn = document.getElementById("add-assignment");
      submitBtn.textContent = "Update Assignment";
      submitBtn.setAttribute("data-edit-id", id);
      
      // Scroll smoothly to form view for better user experience
      assignmentForm.scrollIntoView({ behavior: "smooth" });
    }
  }
}

/**
 * TODO: Implement loadAndInitialize (async).
 *
 * It should:
 * 1. Send a GET to './api/index.php'.
 *    Response shape: { success: true, data: [ ...assignment objects ] }
 * 2. Store the data array in the global `assignments` variable.
 * 3. Call renderTable() to populate the table.
 * 4. Attach the 'submit' event listener to the assignment form
 *    (calls handleAddAssignment).
 * 5. Attach a 'click' event listener to the assignments table body
 *    (calls handleTableClick — event delegation for edit and delete).
 */
async function loadAndInitialize() {
  try {
    // 1. Fetch current items
    const response = await fetch("./api/index.php");
    const result = await response.json();

    if (result.success) {
      // 2. Store globally
      assignments = result.data || [];
      // 3. Initial dynamic table layout setup
      renderTable();
    } else {
      console.error("Failed to load initial data from API server handler.");
    }
  } catch (error) {
    console.error("Error during component load initialization setup:", error);
  }

  // 4. Attach listener submission interface wrapper
  if (assignmentForm) {
    assignmentForm.addEventListener("submit", handleAddAssignment);
  }

  // 5. Explicitly assign row interaction event dispatcher delegation hook
  if (assignmentsTbody) {
    assignmentsTbody.addEventListener("click", handleTableClick);
  }
}

// --- Initial Page Load ---
loadAndInitialize();
