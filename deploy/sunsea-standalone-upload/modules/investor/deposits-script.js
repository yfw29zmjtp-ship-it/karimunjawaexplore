// Toggle deposit group expand/collapse
function toggleDepositGroup(button) {
    const group = button.closest('.investor-deposit-group');
    if (!group) return;
    
    const isExpanded = group.classList.contains('expanded');
    
    if (isExpanded) {
        group.classList.remove('expanded');
        group.classList.add('collapsed');
    } else {
        group.classList.remove('collapsed');
        group.classList.add('expanded');
    }
}
