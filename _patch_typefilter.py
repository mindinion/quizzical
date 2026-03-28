import sys

with open('c:/Users/GGPC/Documents/Quizzical/v2/main.js', 'r', encoding='utf-8') as f:
    c = f.read()

OLD = """	function switchTypeFilter(filter) {
		rankingsTypeFilter = filter;
		rankingsLoaded = false;
		var $chip = $('#TypeFilterChip');
		if (filter === 'main') {
			$chip.text('Morning & Afternoon').removeClass('chip-all');
		} else {
			$chip.text('All types').addClass('chip-all');
		}
		loadRankings(rankingsCurrentPeriod);
	}"""

NEW = """	function switchTypeFilter(filter) {
		rankingsTypeFilter = filter;
		rankingsLoaded = false;
		$('.type-tab').removeClass('type-active');
		$('#TypeTab-' + filter).addClass('type-active');
		loadRankings(rankingsCurrentPeriod);
	}"""

if OLD not in c:
    print('ERROR: switchTypeFilter not found'); sys.exit(1)

c = c.replace(OLD, NEW, 1)
print('Change 1: switchTypeFilter updated')

with open('c:/Users/GGPC/Documents/Quizzical/v2/main.js', 'w', encoding='utf-8') as f:
    f.write(c)
print('Done')
