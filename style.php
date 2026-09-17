.kanban-board-container {
    user-select: none;
}

.kanban-board {
    display: flex;
    align-items: flex-start;
    padding-bottom: 20px;
}

.kanban-column {
    border-radius: 8px;
    border: 1px solid #e1e4e8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.kanban-card {
    cursor: grab;
    border-radius: 6px;
    background-color: #ffffff;
    border: 1px solid #e9ecef !important;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12) !important;
}

.kanban-card.dragging {
    opacity: 0.4;
}

.kanban-card-list {
    overflow-y: auto;
    max-height: 500px;
    scrollbar-width: thin;
    scrollbar-color: rgba(0, 0, 0, 0.2) rgba(0, 0, 0, 0.05);
}

.kanban-card-list::-webkit-scrollbar {
    width: 8px;
}

.kanban-card-list::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.05);
    border-radius: 4px;
}

.kanban-card-list::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 4px;
}

.kanban-card-list::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 0, 0, 0.3);
}

.kanban-card-list.dragover {
    background-color: rgba(0, 123, 255, 0.08);
    border: 2px dashed #007bff;
    border-radius: 6px;
}

/* MODAL STYLING */
.modal {
    display: none;
    position: fixed;
    z-index: 1050;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0, 0, 0, 0.5);
}

.modal.show {
    display: block;
}

.modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 1040;
    width: 100vw;
    height: 100vh;
    background-color: #000;
    opacity: 0.5;
}

.modal-backdrop.show {
    opacity: 0.5;
}

body.modal-open {
    overflow: hidden;
}

#modalCardDetail.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ASSIGNEE CHECKBOX LIST */
.kanban-assignee-list {
    max-height: 160px;
    overflow-y: auto;
    background-color: #ffffff;
}

.kanban-assignee-list .form-check {
    padding: 5px 8px 5px 32px;
    margin-bottom: 2px;
    border-radius: 4px;
}

.kanban-assignee-list .form-check:hover {
    background-color: #f1f3f5;
}

.kanban-assignee-list .form-check-label {
    display: block;
    cursor: pointer;
}