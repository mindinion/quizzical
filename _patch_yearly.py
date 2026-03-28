import sys

with open('c:/Users/GGPC/Documents/Quizzical/v2/main.js', 'r', encoding='utf-8') as f:
    c = f.read()

changes = 0

# 1. rh-trend header
OLD = "if (period !== 'alltime') html += '<span class=\"rh-trend\">"
NEW = "if (period !== 'alltime' && period !== 'yearly') html += '<span class=\"rh-trend\">"
if OLD not in c: print('ERROR: change 1 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 1: rh-trend header')

# 2. rh-part header
OLD = "if (period !== 'alltime') html += '<span class=\"rh-part\">"
NEW = "if (period !== 'alltime' && period !== 'yearly') html += '<span class=\"rh-part\">"
if OLD not in c: print('ERROR: change 2 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 2: rh-part header')

# 3. trendHtml computation block (inside buildRankingRow)
OLD = "\t\t\tvar trendHtml = '';\n\t\t\tif (period !== 'alltime') {"
NEW = "\t\t\tvar trendHtml = '';\n\t\t\tif (period !== 'alltime' && period !== 'yearly') {"
if OLD not in c: print('ERROR: change 3 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 3: trendHtml block')

# 4. partHtml variable
OLD = "var partHtml = period !== 'alltime'"
NEW = "var partHtml = (period !== 'alltime' && period !== 'yearly')"
if OLD not in c: print('ERROR: change 4 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 4: partHtml variable')

# 5. r-trend in row HTML
OLD = "if (period !== 'alltime') s += '<span class=\"r-trend\">' + trendHtml + '</span>';"
NEW = "if (period !== 'alltime' && period !== 'yearly') s += '<span class=\"r-trend\">' + trendHtml + '</span>';"
if OLD not in c: print('ERROR: change 5 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 5: r-trend in row')

# 6. r-part in row HTML
OLD = "if (period !== 'alltime') s += '<span class=\"r-part\">' + partHtml + '</span>';"
NEW = "if (period !== 'alltime' && period !== 'yearly') s += '<span class=\"r-part\">' + partHtml + '</span>';"
if OLD not in c: print('ERROR: change 6 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 6: r-part in row')

# 7. Most Improved section
OLD = "\t\t// Most Improved (weekly / monthly only)\n\t\tif (period !== 'alltime') {"
NEW = "\t\t// Most Improved (weekly / monthly only)\n\t\tif (period !== 'alltime' && period !== 'yearly') {"
if OLD not in c: print('ERROR: change 7 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 7: Most Improved guard')

# 8. formatResultDate - show year for yearly too
OLD = "if (period === 'alltime') s += ' ' + parts[0];"
NEW = "if (period === 'alltime' || period === 'yearly') s += ' ' + parts[0];"
if OLD not in c: print('ERROR: change 8 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 8: formatResultDate year')

with open('c:/Users/GGPC/Documents/Quizzical/v2/main.js', 'w', encoding='utf-8') as f:
    f.write(c)
print(f'Done — {changes} changes applied')
