
function getResults() {
		$.get("action-getresults.php", 
			{ 
				groupid:2,
				num:Number(getCookie("feedItems"))
			},
			function(data, status) { 
				alert(data);
			}
		);
	}
	
	
	getResults();

