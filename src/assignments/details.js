/*
  Requirement: Populate the assignment detail page and discussion forum.
  Implementation: details.js
*/

// --- Global Data Store ---
let currentAssignmentId = null;
let currentComments     = [];

// --- Element Selections ---
const assignmentTitle       = document.getElementById('assignment-title');
const assignmentDueDate     = document.getElementById('assignment-due-date');
const assignmentDescription = document.getElementById('assignment-description');
const assignmentFilesList   = document.getElementById('assignment-files-list');
const commentList           = document.getElementById('comment-list');
const commentForm           = document.getElementById('comment-form');
const newCommentInput       = document.getElementById('new-comment');

// --- Functions ---

/**
 * Reads window.location.search to construct a URLSearchParams object.
 * Returns the value of the 'id' parameter.
 */
function getAssignmentIdFromURL() {
  const params = new URLSearchParams(window.location.search);
  return params.get('id');
}

/**
 * Populates the UI with target assignment detail data.
 * Safe text rendering is applied throughout via textContent.
 * 
 * @param {Object} assignment — the assignment object returned by the API
 */
function renderAssignmentDetails(assignment) {
  assignmentTitle.textContent = assignment.title;
  assignmentDueDate.textContent = "Due: " + assignment.due_date;
  assignmentDescription.textContent = assignment.description;

  // Clear previous entries securely
  assignmentFilesList.innerHTML = "";

  if (Array.isArray(assignment.files)) {
    assignment.files.forEach(url => {
      const listItem = document.createElement('li');
      const anchor = document.createElement('a');
      
      anchor.href = url;
      anchor.textContent = url;
      // Recommended safety precaution for target resource links
      anchor.target = "_blank";
      anchor.rel = "noopener noreferrer";

      listItem.appendChild(anchor);
      assignmentFilesList.appendChild(listItem);
    });
  }
}

/**
 * Creates and returns a single comment <article> element tree.
 * Uses textContent properties to completely eliminate XSS injection vectors.
 * 
 * @param {Object} comment — one comment object from the API
 * @returns {HTMLElement} <article>
 */
function createCommentArticle(comment) {
  const article = document.createElement('article');
  const paragraph = document.createElement('p');
  const footer = document.createElement('footer');

  paragraph.textContent = comment.text;
  footer.textContent = `Posted by: ${comment.author}`;

  article.appendChild(paragraph);
  article.appendChild(footer);

  return article;
}

/**
 * Clears the discussion section and re-injects all existing comment components.
 */
function renderComments() {
  commentList.innerHTML = "";
  
  currentComments.forEach(comment => {
    const commentArticle = createCommentArticle(comment);
    commentList.appendChild(commentArticle);
  });
}

/**
 * Asynchronously processes new comment form submissions to the REST endpoint.
 * 
 * @param {Event} event 
 */
async function handleAddComment(event) {
  event.preventDefault();

  const commentText = newCommentInput.value.trim();
  if (!commentText) {
    return; // Fast return on empty/blank whitespace submissions
  }

  try {
    const response = await fetch('./api/index.php?action=comment', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        assignment_id: parseInt(currentAssignmentId, 10),
        author: "Student", // Hardcoded per implementation rule specifications
        text: commentText
      })
    });

    if (!response.ok) {
      throw new Error(`HTTP network error encountered: ${response.status}`);
    }

    const result = await response.json();

    if (result && result.success === true) {
      // Push backend populated payload (with database auto-generated timestamps)
      currentComments.push(result.data);
      renderComments();
      newCommentInput.value = ""; // Reset input field on successful completion
    } else {
      alert("Error posting comment: " + (result.message || "Unknown error state."));
    }
  } catch (error) {
    console.error("Failed to add comment processing stream:", error);
    alert("Could not post comment at this time. Please check your connectivity.");
  }
}

/**
 * Initializes runtime execution scope requirements.
 * Executes parallel asynchronous operations to retrieve assignment context and messages.
 */
async function initializePage() {
  currentAssignmentId = getAssignmentIdFromURL();

  if (!currentAssignmentId || currentAssignmentId.trim() === "") {
    assignmentTitle.textContent = "Assignment not found.";
    return;
  }

  try {
    // Execute data retrieval pipelines concurrently for optimized performance profiles
    const [assignmentRes, commentsRes] = await Promise.all([
      fetch(`./api/index.php?id=${encodeURIComponent(currentAssignmentId)}`),
      fetch(`./api/index.php?action=comments&assignment_id=${encodeURIComponent(currentAssignmentId)}`)
    ]);

    // Handle initial state checking against response pipelines
    if (!assignmentRes.ok) {
      if (assignmentRes.status === 404) {
        assignmentTitle.textContent = "Assignment not found.";
        return;
      }
      throw new Error("Failed fetching assignment description metadata context.");
    }

    const assignmentData = await assignmentRes.json();
    const commentsData = await commentsRes.json();

    if (assignmentData && assignmentData.success === true && assignmentData.data) {
      // Store returned comments array, fall back to empty array if none exist
      currentComments = (commentsData && commentsData.success === true) ? commentsData.data : [];

      // Render updated UI trees
      renderAssignmentDetails(assignmentData.data);
      renderComments();

      // Configure event listeners
      commentForm.addEventListener('submit', handleAddComment);
    } else {
      assignmentTitle.textContent = "Assignment not found.";
    }

  } catch (error) {
    console.error("Critical Page Load Initialization Defect:", error);
    assignmentTitle.textContent = "Error loading assignment data details.";
  }
}

// --- Initial Page Load Execution Scope Trigger ---
initializePage();
