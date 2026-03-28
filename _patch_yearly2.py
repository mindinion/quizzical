import sys

with open('c:/Users/GGPC/Documents/Quizzical/v2/main.js', 'r', encoding='utf-8') as f:
    c = f.read()

changes = 0

# 1. rh-trend header — revert yearly exclusion
OLD = "if (period !== 'alltime' && period !== 'yearly') html += '<span class=\"rh-trend\">"
NEW = "if (period !== 'alltime') html += '<span class=\"rh-trend\">"
if OLD not in c: print('ERROR: change 1 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 1: rh-trend header')

# 2. rh-part header — revert yearly exclusion
OLD = "if (period !== 'alltime' && period !== 'yearly') html += '<span class=\"rh-part\">"
NEW = "if (period !== 'alltime') html += '<span class=\"rh-part\">"
if OLD not in c: print('ERROR: change 2 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 2: rh-part header')

# 3. trend tooltip text — add yearly case
OLD = "(period === 'weekly' ? 'week' : 'month')"
NEW = "(period === 'weekly' ? 'week' : period === 'yearly' ? 'year' : 'month')"
if OLD not in c: print('ERROR: change 3 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 3: trend tooltip text')

# 4. trendHtml block — revert yearly exclusion
OLD = "if (period !== 'alltime' && period !== 'yearly') {"
NEW = "if (period !== 'alltime') {"
if OLD not in c: print('ERROR: change 4 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 4: trendHtml block')

# 5. partHtml variable — revert yearly exclusion
OLD = "var partHtml = (period !== 'alltime' && period !== 'yearly')"
NEW = "var partHtml = period !== 'alltime'"
if OLD not in c: print('ERROR: change 5 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 5: partHtml variable')

# 6. r-trend in row — revert yearly exclusion
OLD = "if (period !== 'alltime' && period !== 'yearly') s += '<span class=\"r-trend\">' + trendHtml + '</span>';"
NEW = "if (period !== 'alltime') s += '<span class=\"r-trend\">' + trendHtml + '</span>';"
if OLD not in c: print('ERROR: change 6 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 6: r-trend in row')

# 7. r-part in row — revert yearly exclusion
OLD = "if (period !== 'alltime' && period !== 'yearly') s += '<span class=\"r-part\">' + partHtml + '</span>';"
NEW = "if (period !== 'alltime') s += '<span class=\"r-part\">' + partHtml + '</span>';"
if OLD not in c: print('ERROR: change 7 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 7: r-part in row')

# 8. Most Improved — revert yearly exclusion
OLD = "if (period !== 'alltime' && period !== 'yearly') {"
NEW = "if (period !== 'alltime') {"
if OLD not in c: print('ERROR: change 8 not found'); sys.exit(1)
c = c.replace(OLD, NEW, 1); changes += 1; print('Change 8: Most Improved guard')

# formatResultDate year check stays as-is (period === 'alltime' || period === 'yearly')
# Verify it's still there
if "period === 'alltime' || period === 'yearly'" not in c:
    print('WARNING: formatResultDate year check not found — was it lost?')
else:
    print('OK: formatResultDate year check still present')

with open('c:/Users/GGPC/Documents/Quizzical/v2/main.js', 'w', encoding='utf-8') as f:
    f.write(c)
print(f'Done — {changes} changes applied')
