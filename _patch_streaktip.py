import sys

with open('c:/Users/GGPC/Documents/Quizzical/v2/main.js', 'r', encoding='utf-8') as f:
    c = f.read()

changes = 0

# 1. Add data-tip to the streak badge
OLD = "var streakBadge = streak >= 3 ? '<span class=\"streak-badge\">&#x1F525; ' + streak + '</span>' : '';"
NEW = "var streakBadge = streak >= 3 ? '<span class=\"streak-badge\" data-tip=\"' + streak + '-day streak — results posted on ' + streak + ' consecutive days\">&#x1F525; ' + streak + '</span>' : '';"
if OLD not in c: print('ERROR: change 1 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 1: streak badge data-tip')

# 2. Extend the tooltip click handler to include .streak-badge
OLD = "\t\t$(document).on('click', '.info-icon', function(e) {"
NEW = "\t\t$(document).on('click', '.info-icon, .streak-badge[data-tip]', function(e) {"
if OLD not in c: print('ERROR: change 2 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 2: tooltip click handler')

# 3. Prevent streak badge click from also triggering row drill-down
OLD = "\t\t\tif ($(e.target).hasClass('info-icon')) return;"
NEW = "\t\t\tif ($(e.target).hasClass('info-icon') || $(e.target).hasClass('streak-badge')) return;"
if OLD not in c: print('ERROR: change 3 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 3: drill-down guard')

with open('c:/Users/GGPC/Documents/Quizzical/v2/main.js', 'w', encoding='utf-8') as f:
    f.write(c)
print(f'Done — {changes} changes applied')
