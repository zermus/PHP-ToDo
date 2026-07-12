// Task list AJAX: complete/uncomplete tasks and checklist items.
// One delegated listener; endpoints and CSRF token come from data attributes.
document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('task-page');
    if (!page) {
        return;
    }

    var csrfToken = page.dataset.csrf;

    function post(url, params, onSuccess, onError) {
        params.csrf_token = csrfToken;
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(params).toString()
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    onSuccess(data);
                } else {
                    onError(data.error || 'Request failed.');
                }
            })
            .catch(function (error) {
                console.error('Error:', error);
                onError('Network error.');
            });
    }

    page.addEventListener('click', function (event) {
        var taskButton = event.target.closest('.complete-task');
        if (taskButton) {
            var taskId = taskButton.dataset.taskId;
            var taskElement = document.getElementById('task-' + taskId);
            var wasCompleted = taskElement.classList.contains('task-completed');

            post(page.dataset.completeUrl, { task_id: taskId }, function (data) {
                var isCompleted = data.newState === 1;
                taskElement.classList.toggle('task-completed', isCompleted);
                taskButton.textContent = isCompleted ? 'Uncomplete Task' : 'Complete Task';
            }, function (error) {
                taskElement.classList.toggle('task-completed', wasCompleted);
                alert('Error: ' + error);
            });

            return;
        }

        var itemButton = event.target.closest('.complete-checklist-item');
        if (itemButton) {
            var itemId = itemButton.dataset.itemId;
            var itemElement = document.getElementById('item-' + itemId);
            var isCompleted = !itemElement.classList.contains('completed');

            post(page.dataset.checklistUrl, { item_id: itemId, is_completed: isCompleted ? 1 : 0 }, function () {
                itemElement.classList.toggle('completed', isCompleted);
                itemButton.textContent = isCompleted ? 'Uncomplete' : 'Complete';
            }, function (error) {
                alert('Error: ' + error);
            });
        }
    });
});
