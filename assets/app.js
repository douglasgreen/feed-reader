document.addEventListener('DOMContentLoaded', function() {
    // Edit Feed Modal
    const editFeedModal = document.getElementById('editFeedModal');
    if (editFeedModal) {
        editFeedModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('editFeedId').value = button.dataset.feedId;
            document.getElementById('editFeedName').value = button.dataset.feedName;
            document.getElementById('editFeedUrl').value = button.dataset.feedUrl;
            document.getElementById('editFeedGroup').value = button.dataset.feedGroup;
        });
    }

    // Edit Filter Modal
    const editFilterModal = document.getElementById('editFilterModal');
    if (editFilterModal) {
        editFilterModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('editFilterId').value = button.dataset.filterId;
            document.getElementById('editFilterString').value = button.dataset.filterString;
        });
    }

    // Delete Filter Modal
    const deleteFilterModal = document.getElementById('deleteFilterModal');
    if (deleteFilterModal) {
        deleteFilterModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('deleteFilterId').value = button.dataset.filterId;
            document.getElementById('deleteFilterName').textContent = button.dataset.filterString;
        });
    }

    // Delete Feed Modal
    const deleteFeedModal = document.getElementById('deleteFeedModal');
    if (deleteFeedModal) {
        deleteFeedModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('deleteFeedId').value = button.dataset.feedId;
            document.getElementById('deleteFeedName').textContent = button.dataset.feedName;
        });
    }

    // Edit Group Modal
    const editGroupModal = document.getElementById('editGroupModal');
    if (editGroupModal) {
        editGroupModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('editGroupId').value = button.dataset.groupId;
            document.getElementById('editGroupName').value = button.dataset.groupName;
        });
    }

    // Expandable excerpts
    const excerpts = document.querySelectorAll('.excerpt');
    excerpts.forEach(function(excerpt) {
        const text = excerpt.textContent || excerpt.innerText;
        if (text.length > 500) {
            const button = document.createElement('button');
            button.className = 'btn btn-sm btn-outline-secondary mt-2';
            button.textContent = '▼ Show full excerpt';
            excerpt.parentNode.insertBefore(button, excerpt);
            excerpt.style.display = 'none';
            button.addEventListener('click', function() {
                button.style.display = 'none';
                excerpt.style.display = 'block';
            });
        }
    });
});
