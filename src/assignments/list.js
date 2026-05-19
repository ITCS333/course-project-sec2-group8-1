/*
  Requirement: Populate the "Course Assignments" list page.
  Implementation: list.js
*/

// --- Element Selections ---
// Selects the section container that holds the dynamic assignment card list
const assignmentListSection = document.getElementById('assignment-list-section');

// --- Functions ---

/**
 * Creates and returns an <article> element matching the design architecture.
 * Safe text rendering properties (textContent) are utilized to eliminate injection risks.
 *
 * @param {Object} assignment — one object from the API response
 * @returns {HTMLElement} <article>
 */
function createAssignmentArticle(assignment) {
  // Create all necessary elements for structural semantics
  const article = document.createElement('article');
  const heading = document.createElement('h2');
  const dueDatePara = document.createElement('p');
  const descPara = document.createElement('p');
  const detailsLink = document.createElement('a');

  // Populate textual parameters safely
  heading.textContent = assignment.title;
  dueDatePara.textContent = "Due: " + assignment.due_date; // Using the SQL column layout template
  descPara.textContent = assignment.description;
  
  // Configure the anchor target linking directly to the dynamic details view page
  detailsLink.href = `details.html?id=${encodeURIComponent(assignment.id)}`;
  detailsLink.textContent = "View Details & Discussion";

  // Build the hierarchical component tree layout
  article.appendChild(heading);
  article.appendChild(dueDatePara);
  article.appendChild(descPara);
  article.appendChild(detailsLink);

  return article;
}

/**
 * Asynchronously fetches the entire assignments catalog array from the API endpoint,
 * cleans the interface viewport canvas, and loads the active item cards.
 */
async function loadAssignments() {
  try {
    const response = await fetch('./api/index.php');

    if (!response.ok) {
      throw new Error(`Network tracking profile error encountered: ${response.status}`);
    }

    const result = await response.json();

    // Securely flush previous placeholders or broken state items 
    assignmentListSection.innerHTML = "";

    if (result && result.success === true && Array.isArray(result.data)) {
      if (result.data.length === 0) {
        // Optional user experience optimization: handle empty states elegantly
        const emptyMessage = document.createElement('p');
        emptyMessage.textContent = "No current course assignments are posted.";
        emptyMessage.style.color = "var(--text-muted)";
        assignmentListSection.appendChild(emptyMessage);
        return;
      }

      // Loop through assignments data records to append each node block
      result.data.forEach(assignment => {
        const assignmentCard = createAssignmentArticle(assignment);
        assignmentListSection.appendChild(assignmentCard);
      });
    } else {
      console.error("API indicated failure processing payload response:", result);
      assignmentListSection.textContent = "Failed to load course assignments data models.";
    }

  } catch (error) {
    console.error("Critical error catch during data execution flow initialization:", error);
    assignmentListSection.textContent = "Unable to reach the server. Please verify connection metrics.";
  }
}

// --- Initial Page Load Sequence Trigger ---
loadAssignments();
