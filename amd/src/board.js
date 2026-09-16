define(['core/ajax', 'core/notification'], function(ajax, notification) {
    return {
        init: function(cmid, kanbanid) {
            var draggedCard = null;
            var isDragging = false;

            // ==========================================
            // 1. KÉO THẢ THẺ (DRAG & DROP)
            // ==========================================
            document.querySelectorAll('.kanban-card').forEach(function(card) {
                card.addEventListener('dragstart', function(e) {
                    isDragging = true;
                    draggedCard = this;
                    this.classList.add('dragging');
                    e.dataTransfer.setData('text/plain', this.dataset.cardid);
                });

                card.addEventListener('dragend', function() {
                    this.classList.remove('dragging');
                    draggedCard = null;
                    setTimeout(function() {
                        isDragging = false;
                    }, 100);
                });
            });

            // Vùng thả thẻ (Columns)
            document.querySelectorAll('.kanban-card-list').forEach(function(column) {
                column.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('dragover');
                    if (draggedCard) {
                        var afterElement = Array.from(this.querySelectorAll('.kanban-card:not(.dragging)'))
                            .find(function(card) {
                                var rect = card.getBoundingClientRect();
                                return e.clientY < rect.top + rect.height / 2;
                            });
                        if (afterElement) {
                            this.insertBefore(draggedCard, afterElement);
                        } else {
                            this.appendChild(draggedCard);
                        }
                    }
                });

                column.addEventListener('dragleave', function() {
                    this.classList.remove('dragover');
                });

                column.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                    if (draggedCard) {
                        var targetColumnId = this.dataset.columnid;
                        var cardId = draggedCard.dataset.cardid;
                        var newPosition = Array.from(this.querySelectorAll('.kanban-card'))
                            .indexOf(draggedCard);

                        var placeholder = this.querySelector('.empty-column-placeholder');
                        if (placeholder) {
                            placeholder.remove();
                        }

                        ajax.call([{
                            methodname: 'mod_kanban_move_card',
                            args: {
                                cardid: parseInt(cardId),
                                targetcolumnid: parseInt(targetColumnId),
                                cmid: parseInt(cmid),
                                newposition: newPosition
                            }
                        }])[0].done(function(response) {
                            if (response.status) {
                                window.location.reload();
                            }
                        }).fail(notification.exception);
                    }
                });
            });

            // ==========================================
            // 2. ĐIỀU KHIỂN POPUP MODAL (CHUẨN 100%)
            // ==========================================
            var modal = document.getElementById('modalAddCard');
            var errorAlert = document.getElementById('card-error-alert');
            var attachmentsInput = document.getElementById('card-attachments-input');

            /**
             * Opens the card creation modal for a column.
             *
             * @param {number} columnId Column identifier.
             */
            function openModal(columnId) {
                // re-query modal in case it wasn't in DOM at init time
                modal = document.getElementById('modalAddCard');
                if (!modal) {
                    return;
                }
                var colInput = document.getElementById('card-column-id');
                if (colInput) {
                    colInput.value = columnId;
                }
                document.getElementById('card-title-input').value = '';
                document.getElementById('card-desc-input').value = '';
                document.getElementById('card-duedate-input').value = '';
                document.getElementById('card-url-input').value = '';
                attachmentsInput = document.getElementById('card-attachments-input');
                if (attachmentsInput) {
                    attachmentsInput.value = '';
                }
                var assigneeSelect = document.getElementById('card-assignee-input');
                if (assigneeSelect) {
                    Array.from(assigneeSelect.options).forEach(function(option) {
                        option.selected = false;
                    });
                }
                if (errorAlert) {
                    errorAlert.classList.add('d-none');
                    errorAlert.innerText = '';
                }

                // Hiển thị Modal & Lớp mờ nền
                modal.style.display = 'block';
                modal.classList.add('show');
                document.body.classList.add('modal-open');

                var backdrop = document.getElementById('custom-modal-backdrop');
                if (!backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.id = 'custom-modal-backdrop';
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                }
            }

            /**
             * Closes the card creation modal.
             */
            function closeModal() {
                if (!modal) {
                    return;
                }
                modal.style.display = 'none';
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');
                var backdrop = document.getElementById('custom-modal-backdrop');
                if (backdrop) {
                    backdrop.remove();
                }
            }

            // ==========================================
            // XEM CHI TIẾT CÔNG VIỆC (MODAL DETAIL)
            // ==========================================
            var detailModal = null;

            /**
             * Opens the detail modal and loads card activity.
             *
             * @param {HTMLElement} cardElement Card element.
             */
            function openDetailModal(cardElement) {
                if (isDragging) {
                    return;
                }

                detailModal = document.getElementById('modalCardDetail');
                if (!detailModal) {
                    return;
                }

                var cardId = cardElement.dataset.cardid;
                detailModal.dataset.cardid = cardId;
                var commentsEl = document.getElementById('detail-card-comments');
                var historyEl = document.getElementById('detail-card-history');
                var attachmentsEl = document.getElementById('detail-card-attachments');
                commentsEl.innerHTML = '<div class="text-muted">Đang tải...</div>';
                if (historyEl) {
                    historyEl.innerHTML = '';
                }
                if (attachmentsEl) {
                    attachmentsEl.innerHTML = '<div class="text-muted">Đang tải file đính kèm...</div>';
                }
                ajax.call([{
                    methodname: 'mod_kanban_get_card_activity',
                    args: {cardid: parseInt(cardId), cmid: parseInt(cmid)}
                }])[0].done(function(activity) {
                    commentsEl.innerHTML = activity.comments.length ? activity.comments.map(function(item) {
                        return '<div class="border-bottom py-2"><strong>' + escapeHtml(item.author) + '</strong>' +
                            '<small class="text-muted ms-2">' + escapeHtml(item.timecreated) + '</small><div>' +
                            escapeHtml(item.comment) + '</div></div>';
                    }).join('') : '<div class="text-muted">Chưa có bình luận.</div>';
                    if (historyEl) {
                        historyEl.innerHTML = activity.history.length ? activity.history.map(function(item) {
                            return '<div class="border-bottom py-2"><strong>' + escapeHtml(item.author) + '</strong> ' +
                                escapeHtml(item.details || item.action) + '<small class="text-muted d-block">' +
                                escapeHtml(item.timecreated) + '</small></div>';
                        }).join('') : '<div class="text-muted">Chưa có lịch sử.</div>';
                    }
                }).fail(function() {
                    commentsEl.innerHTML = '<div class="text-danger">Không thể tải hoạt động của thẻ.</div>';
                });

                ajax.call([{
                    methodname: 'mod_kanban_get_card_files',
                    args: {cardid: parseInt(cardId), cmid: parseInt(cmid)}
                }])[0].done(function(response) {
                    if (!attachmentsEl) {
                        return;
                    }
                    if (!response.files || !response.files.length) {
                        attachmentsEl.innerHTML = '<span class="text-muted">Chưa có file đính kèm.</span>';
                        return;
                    }
                    attachmentsEl.innerHTML = response.files.map(function(file) {
                        var deleteButton = '';
                        deleteButton = '<button type="button" class="btn btn-link btn-sm text-danger p-0 ms-2 ' +
                            'delete-card-file" data-filehash="' + escapeHtml(file.hash) + '" data-cardid="' +
                            cardId + '" title="Xóa file"><i class="fa fa-trash"></i></button>';
                        return '<div class="d-flex align-items-center justify-content-between border-bottom py-2">' +
                            '<a href="' + escapeHtml(file.url) + '" target="_blank" ' +
                            'class="text-primary text-decoration-underline me-2">' +
                            escapeHtml(file.filename) + '</a><small class="text-muted">(' +
                            (file.size || 0) + ' bytes)</small>' + deleteButton + '</div>';
                    }).join('');
                }).fail(function() {
                    if (attachmentsEl) {
                        attachmentsEl.innerHTML = '<span class="text-muted">Không thể tải file đính kèm.</span>';
                    }
                });
                var titleEl = cardElement.querySelector('.fw-bold.text-dark');
                var descEl = cardElement.querySelector('.text-muted.small.text-truncate');
                var title = titleEl ? titleEl.textContent.trim() : 'Không có tiêu đề';
                var desc = descEl ? descEl.textContent.trim() : 'Không có mô tả';

                // Lấy thông tin hạn hoàn thành từ modal
                var duedateText = '';
                var alertClass = 'd-none';
                var alertText = '';

                var dueDateContainer = cardElement.querySelector('.mt-2.pt-2.border-top');
                if (dueDateContainer && dueDateContainer.querySelector('.badge.bg-danger')) {
                    duedateText = dueDateContainer.querySelector('.badge.bg-danger').textContent.trim();
                    alertClass = '';
                    alertText = '⚠️ Công việc này đã quá hạn!';
                } else if (dueDateContainer && dueDateContainer.querySelector('.badge.bg-warning')) {
                    duedateText = dueDateContainer.querySelector('.badge.bg-warning').textContent.trim();
                    alertClass = '';
                    alertText = '⏰ Công việc sắp đến hạn!';
                } else if (dueDateContainer && dueDateContainer.querySelector('small.text-muted')) {
                    duedateText = dueDateContainer.querySelector('small.text-muted').textContent.trim();
                }

                // Cập nhật thông tin trong modal
                document.getElementById('detail-card-title').textContent = title;
                document.getElementById('detail-card-desc').textContent = desc || 'Không có mô tả';
                document.getElementById('detail-card-assignee').textContent = cardElement.dataset.assignees || 'Chưa phân công';
                document.getElementById('detail-card-duedate').textContent = duedateText || 'Không có hạn hoàn thành';
                document.getElementById('detail-card-status').textContent =
                    cardElement.dataset.columntitle || 'Không xác định';

                // Xử lý hiển thị đường link
                var submissionUrl = cardElement.dataset.submissionurl;
                var urlContainer = document.getElementById('detail-card-url-container');
                var urlAnchor = document.getElementById('detail-card-url');
                //Phần thêm kiểm tra nếu submissionUrl là null hoặc undefined, thì không hiển thị link
                if (submissionUrl && submissionUrl.trim() !== '') {
                    urlAnchor.href = submissionUrl;
                    urlAnchor.textContent = submissionUrl;
                    urlContainer.classList.remove('d-none'); // Gỡ bỏ class ẩn
                } else {
                    urlContainer.classList.add('d-none'); // Giữ nguyên ẩn nếu không có link
                }

                var alertEl = document.getElementById('detail-card-alert');
                if (alertClass === '') {
                    alertEl.classList.remove('d-none');
                    alertEl.textContent = alertText;
                } else {
                    alertEl.classList.add('d-none');
                }

                // Hiển thị modal
                detailModal.style.display = 'block';
                detailModal.classList.add('show');
                document.body.classList.add('modal-open');

                var backdrop = document.getElementById('detail-modal-backdrop');
                if (!backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.id = 'detail-modal-backdrop';
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                }
            }

            /**
             * Escapes text before inserting it into HTML.
             *
             * @param {string} value Text to escape.
             * @returns {string} Escaped HTML.
             */
            function escapeHtml(value) {
                var div = document.createElement('div');
                div.textContent = value || '';
                return div.innerHTML;
            }

            /**
             * Closes the card detail modal.
             */
            function closeDetailModal() {
                if (!detailModal) {
                    return;
                }
                detailModal.style.display = 'none';
                detailModal.classList.remove('show');
                document.body.classList.remove('modal-open');
                var backdrop = document.getElementById('detail-modal-backdrop');
                if (backdrop) {
                    backdrop.remove();
                }
            }

            // ==========================================
            // 2b. SỬA THẺ (gồm sửa người phụ trách)
            // ==========================================
            var editModal = null;
            var editErrorAlert = null;

            /**
             * Converts a unix timestamp to datetime-local input value.
             *
             * @param {number} timestamp Unix timestamp in seconds.
             * @returns {string} Value suitable for datetime-local input.
             */
            function timestampToDatetimeLocal(timestamp) {
                if (!timestamp) {
                    return '';
                }
                var d = new Date(timestamp * 1000);
                var pad = function(n) {
                    return (n < 10 ? '0' : '') + n;
                };
                return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
                    'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            }

            /**
             * Opens the edit modal and prefills card data.
             *
             * @param {HTMLElement} cardElement Card element.
             */
            function openEditModal(cardElement) {
                if (isDragging) {
                    return;
                }
                editModal = document.getElementById('modalEditCard');
                if (!editModal) {
                    return;
                }
                editErrorAlert = document.getElementById('edit-card-error-alert');
                var cardId = cardElement.dataset.cardid;
                document.getElementById('edit-card-id').value = cardId;
                document.getElementById('edit-card-title-input').value = cardElement.dataset.title || '';
                var rawDesc = cardElement.querySelector('.card-desc-raw');
                document.getElementById('edit-card-desc-input').value = rawDesc ? rawDesc.textContent.trim() : '';
                document.getElementById('edit-card-duedate-input').value =
                    timestampToDatetimeLocal(parseInt(cardElement.dataset.duedate || '0', 10));
                document.getElementById('edit-card-url-input').value = cardElement.dataset.submissionurl || '';
                var selectedIds = (cardElement.dataset.assigneeids || '').split(',').map(function(s) {
                    return s.trim();
                }).filter(function(s) {
                    return s !== '';
                });
                var editAssigneeSelect = document.getElementById('edit-card-assignee-input');
                if (editAssigneeSelect) {
                    Array.from(editAssigneeSelect.options).forEach(function(option) {
                        option.selected = selectedIds.indexOf(option.value) !== -1;
                    });
                }
                if (editErrorAlert) {
                    editErrorAlert.classList.add('d-none');
                    editErrorAlert.innerText = '';
                }
                editModal.style.display = 'block';
                editModal.classList.add('show');
                document.body.classList.add('modal-open');
                var backdrop = document.getElementById('edit-modal-backdrop');
                if (!backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.id = 'edit-modal-backdrop';
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                }
            }

            /**
             * Closes the edit modal.
             */
            function closeEditModal() {
                if (!editModal) {
                    editModal = document.getElementById('modalEditCard');
                }
                if (!editModal) {
                    return;
                }
                editModal.style.display = 'none';
                editModal.classList.remove('show');
                document.body.classList.remove('modal-open');
                var backdrop = document.getElementById('edit-modal-backdrop');
                if (backdrop) {
                    backdrop.remove();
                }
            }

            document.addEventListener('click', function(e) {
                var editButton = e.target.closest && e.target.closest('.btn-edit-card');
                if (editButton) {
                    e.preventDefault();
                    e.stopPropagation();
                    var editCard = editButton.closest('.kanban-card');
                    if (editCard) {
                        openEditModal(editCard);
                    }
                    return;
                }
                var dismissEdit = e.target.closest && e.target.closest(
                    '#modalEditCard [data-bs-dismiss="modal"], #modalEditCard .btn-close, ' +
                    '#modalEditCard .btn-secondary'
                );
                if (dismissEdit) {
                    closeEditModal();
                    return;
                }
                if (editModal && editModal.style.display === 'block' && e.target.id === 'edit-modal-backdrop') {
                    closeEditModal();
                }
            });

            document.addEventListener('click', function(e) {
                if (e.target.closest && e.target.closest('#btn-update-card')) {
                    var cardIdVal = document.getElementById('edit-card-id').value;
                    var newTitle = document.getElementById('edit-card-title-input').value.trim();
                    var newDesc = document.getElementById('edit-card-desc-input').value.trim();
                    var newDuedateStr = document.getElementById('edit-card-duedate-input').value;
                    var newUrl = document.getElementById('edit-card-url-input').value.trim();
                    var newAssigneeSelect = document.getElementById('edit-card-assignee-input');
                    var newAssigneeIds = [];
                    if (newAssigneeSelect) {
                        newAssigneeIds = Array.from(newAssigneeSelect.selectedOptions).map(function(option) {
                            return parseInt(option.value, 10);
                        }).filter(function(value) {
                            return !isNaN(value);
                        });
                    }
                    editErrorAlert = document.getElementById('edit-card-error-alert');
                    if (!newTitle) {
                        editErrorAlert.innerText = 'Vui lòng nhập tên công việc!';
                        editErrorAlert.classList.remove('d-none');
                        return;
                    }
                    var newDuedateTimestamp = 0;
                    if (newDuedateStr) {
                        var selectedDate = new Date(newDuedateStr);
                        var now = new Date();
                        if (selectedDate.getTime() < (now.getTime() - 60000)) {
                            editErrorAlert.innerText = 'Lỗi: Hạn hoàn thành không thể là thời gian trong quá khứ!';
                            editErrorAlert.classList.remove('d-none');
                            return;
                        }
                        newDuedateTimestamp = Math.floor(selectedDate.getTime() / 1000);
                    }
                    ajax.call([{
                        methodname: 'mod_kanban_update_card',
                        args: {
                            cardid: parseInt(cardIdVal),
                            cmid: parseInt(cmid),
                            title: newTitle,
                            description: newDesc,
                            duedate: newDuedateTimestamp,
                            submissionurl: newUrl,
                            assignees: newAssigneeIds
                        }
                    }])[0].done(function(response) {
                        if (response.status) {
                            closeEditModal();
                            window.location.reload();
                        }
                    }).fail(function(error) {
                        var message = (error && error.message) ? error.message : 'Có lỗi xảy ra!';
                        editErrorAlert.innerText = message;
                        editErrorAlert.classList.remove('d-none');
                    });
                }
            });

            // Thêm click handler cho các card (xem chi tiết)
            document.addEventListener('click', function(e) {
                if (isDragging) {
                    return;
                }

                if (e.target.closest && (e.target.closest('.btn-edit-card') || e.target.closest('#modalEditCard'))) {
                    return;
                }

                var viewButton = e.target.closest && e.target.closest('.btn-view-card');
                if (viewButton) {
                    e.preventDefault();
                    e.stopPropagation();
                    var viewCard = viewButton.closest('.kanban-card');
                    if (viewCard) {
                        openDetailModal(viewCard);
                    }
                    return;
                }

                var card = e.target.closest && e.target.closest('.kanban-card');
                if (card && !e.target.closest('.btn-delete-card') && !e.target.closest('.btn-edit-card') &&
                    !e.target.closest('.btn-view-card') && !e.target.closest('.badge')) {
                    openDetailModal(card);
                    return;
                }
            });

            document.addEventListener('click', function(e) {
                var submit = e.target.closest && e.target.closest('#detail-card-comment-submit');
                if (!submit) {
                    return;
                }
                var input = document.getElementById('detail-card-comment-input');
                var cardId = detailModal.dataset.cardid;
                var card = document.querySelector('.kanban-card[data-cardid="' + cardId + '"]');
                if (!input.value.trim() || !cardId) {
                    return;
                }
                ajax.call([{
                    methodname: 'mod_kanban_add_comment',
                    args: {cardid: parseInt(cardId), cmid: parseInt(cmid), comment: input.value.trim()}
                }])[0].done(function() {
                    input.value = '';
                    if (card) {
                        openDetailModal(card);
                    }
                }).fail(notification.exception);
            });

            document.addEventListener('click', function(e) {
                var deleteFileBtn = e.target.closest && e.target.closest('.delete-card-file');
                if (!deleteFileBtn) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                var fileHash = deleteFileBtn.dataset.filehash;
                var cardId = deleteFileBtn.dataset.cardid;
                if (!fileHash || !cardId) {
                    return;
                }
                if (!confirm('Bạn có chắc muốn xóa file đính kèm này không?')) {
                    return;
                }
                ajax.call([{
                    methodname: 'mod_kanban_delete_card_file',
                    args: {cardid: parseInt(cardId), cmid: parseInt(cmid), filehash: fileHash}
                }])[0].done(function() {
                    var card = document.querySelector('.kanban-card[data-cardid="' + cardId + '"]');
                    if (card) {
                        openDetailModal(card);
                    }
                }).fail(notification.exception);
            });

            // Đóng detail modal
            document.addEventListener('click', function(e) {
                if (isDragging) {
                    return;
                }
                var closeBtn = e.target.closest && e.target.closest('#modalCardDetail .btn-close, #modalCardDetail .btn-secondary');
                if (closeBtn) {
                    closeDetailModal();
                    return;
                }
            });

            // Đóng modal khi click vào backdrop
            document.addEventListener('click', function(e) {
                if (detailModal && detailModal.style.display === 'block' && e.target.id === 'detail-modal-backdrop') {
                    closeDetailModal();
                }
            });

            // Use event delegation so handlers work even if elements are added later
            document.addEventListener('click', function(e) {
                var btn = e.target.closest && e.target.closest('.btn-open-modal');
                if (btn) {
                    e.preventDefault();
                    var colId = btn.dataset.columnid;
                    openModal(colId);
                    return;
                }

                var dismissAdd = e.target.closest && e.target.closest(
                    '#modalAddCard [data-bs-dismiss="modal"], #modalAddCard .btn-close, ' +
                    '#modalAddCard .modal .btn-secondary'
                );
                if (dismissAdd) {
                    closeModal();
                    return;
                }

            });

            // ==========================================
            // 3. XỬ LÝ TẠO THẺ & KIỂM TRA THỜI GIAN
            // ==========================================
            var btnSubmit = document.getElementById('btn-submit-card');
            if (btnSubmit) {
                btnSubmit.addEventListener('click', function() {
                    var columnId = document.getElementById('card-column-id').value;
                    var title = document.getElementById('card-title-input').value.trim();
                    var desc = document.getElementById('card-desc-input').value.trim();
                    var duedateStr = document.getElementById('card-duedate-input').value;
                    var assigneeSelect = document.getElementById('card-assignee-input');
                    var assigneeIds = [];
                    if (assigneeSelect) {
                        assigneeIds = Array.from(assigneeSelect.selectedOptions).map(function(option) {
                            return parseInt(option.value, 10);
                        }).filter(function(value) {
                            return !isNaN(value);
                        });
                    }

                    // Validate Tên công việc
                    if (!title) {
                        errorAlert.innerText = 'Vui lòng nhập tên công việc!';
                        errorAlert.classList.remove('d-none');
                        return;
                    }

                    // Validate Ngày tháng
                    var duedateTimestamp = 0;
                    if (duedateStr) {
                        var selectedDate = new Date(duedateStr);
                        var now = new Date();

                        // Kiểm tra nếu ngày chọn ở quá khứ
                        if (selectedDate.getTime() < (now.getTime() - 60000)) {
                            errorAlert.innerText = 'Lỗi: Hạn hoàn thành không thể là thời gian trong quá khứ!';
                            errorAlert.classList.remove('d-none');
                            return;
                        }

                        duedateTimestamp = Math.floor(selectedDate.getTime() / 1000);
                    }

                    var submissionUrl = document.getElementById('card-url-input').value.trim();
                    var formData = new FormData();
                    formData.append('kanbanid', kanbanid);
                    formData.append('columnid', columnId);
                    formData.append('cmid', cmid);
                    formData.append('title', title);
                    formData.append('description', desc);
                    formData.append('duedate', duedateTimestamp);
                    formData.append('submissionurl', submissionUrl);
                    formData.append('sesskey', M.cfg.sesskey);
                    assigneeIds.forEach(function(assigneeId) {
                        formData.append('assignees[]', assigneeId);
                    });
                    if (attachmentsInput && attachmentsInput.files && attachmentsInput.files.length) {
                        Array.from(attachmentsInput.files).forEach(function(file) {
                            formData.append('attachments[]', file);
                        });
                    }

                    fetch(M.cfg.wwwroot + '/mod/kanban/upload_and_create_card.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    }).then(function(response) {
                        return response.text().then(function(text) {
                            var data;
                            try {
                                data = JSON.parse(text);
                            } catch (parseError) {
                                throw new Error('Server trả về không phải JSON (HTTP ' +
                                    response.status + '): ' + (text || 'trống').substring(0, 300));
                            }
                            if (!response.ok || !data.status) {
                                throw new Error(data.message || ('Server lỗi HTTP ' + response.status));
                            }
                            return data;
                        });
                    }).then(function() {
                        closeModal();
                        window.location.reload();
                    }).catch(function(error) {
                        errorAlert.innerText = error.message || 'Có lỗi xảy ra!';
                        errorAlert.classList.remove('d-none');
                    });
                });
            }

            /**
             * Calls a column management external function.
             *
             * @param {string} methodname External function name.
             * @param {Object} args External function arguments.
             * @returns {Promise} Moodle AJAX request.
             */
            function columnRequest(methodname, args) {
                args.cmid = parseInt(cmid);
                return ajax.call([{methodname: methodname, args: args}])[0];
            }

            /**
             * Gets column IDs in their current visual order.
             *
             * @returns {number[]} Column identifiers.
             */
            function getColumnIds() {
                return Array.from(document.querySelectorAll('.kanban-column')).map(function(column) {
                    return parseInt(column.dataset.columnid, 10);
                });
            }

            document.addEventListener('click', function(e) {
                var addButton = e.target.closest && e.target.closest('.btn-add-column');
                var editButton = e.target.closest && e.target.closest('.btn-edit-column');
                var deleteButton = e.target.closest && e.target.closest('.btn-delete-column');
                var moveButton = e.target.closest && e.target.closest('.btn-move-column');

                if (addButton || editButton) {
                    var editing = !!editButton;
                    var data = editing ? editButton.dataset : {};
                    var title = prompt('Tên cột:', data.title || '');
                    if (title === null) {
                        return;
                    }
                    var description = prompt('Mô tả cột:', data.description || '');
                    if (description === null) {
                        return;
                    }
                    var color = prompt('Màu cột dạng #RRGGBB:', data.color || '#f4f5f7');
                    if (color === null) {
                        return;
                    }
                    var wip = prompt('WIP limit (0 = không giới hạn):', data.wip || '0');
                    if (wip === null) {
                        return;
                    }
                    var request;
                    if (editing) {
                        request = columnRequest('mod_kanban_update_column', {
                            columnid: parseInt(data.columnid),
                            title: title,
                            description: description,
                            color: color,
                            wip_limit: parseInt(wip, 10)
                        });
                    } else {
                        request = columnRequest('mod_kanban_create_column', {
                            kanbanid: parseInt(kanbanid),
                            title: title,
                            description: description,
                            color: color,
                            wip_limit: parseInt(wip, 10)
                        });
                    }
                    request.done(function() {
                        window.location.reload();
                    }).fail(notification.exception);
                    return;
                }

                if (deleteButton) {
                    if (!confirm('Xóa cột "' + deleteButton.dataset.title +
                        '"? Cột phải rỗng mới được xóa.')) {
                        return;
                    }
                    columnRequest('mod_kanban_delete_column', {
                        columnid: parseInt(deleteButton.dataset.columnid)
                    }).done(function() {
                        window.location.reload();
                    }).fail(notification.exception);
                    return;
                }

                if (moveButton) {
                    var ids = getColumnIds();
                    var columnId = parseInt(moveButton.dataset.columnid);
                    var index = ids.indexOf(columnId);
                    var target = index + parseInt(moveButton.dataset.direction, 10);
                    if (index < 0 || target < 0 || target >= ids.length) {
                        return;
                    }
                    var moved = ids.splice(index, 1)[0];
                    ids.splice(target, 0, moved);
                    columnRequest('mod_kanban_reorder_columns', {
                        kanbanid: parseInt(kanbanid),
                        columnids: ids
                    }).done(function() {
                        window.location.reload();
                    }).fail(notification.exception);
                }
            });

            // ==========================================
            // 4. XỬ LÝ XÓA THẺ
            // ==========================================
            document.querySelectorAll('.btn-delete-card').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var cardId = this.dataset.cardid;
                    if (confirm('Bạn có chắc chắn muốn xóa công việc này không?')) {
                        ajax.call([{
                            methodname: 'mod_kanban_delete_card',
                            args: {
                                cardid: parseInt(cardId),
                                cmid: parseInt(cmid)
                            }
                        }])[0].done(function(response) {
                            if (response.status) {
                                window.location.reload();
                            }
                        }).fail(notification.exception);
                    }
                });
            });
        }
    };
});