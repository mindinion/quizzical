<?php	
	ini_set('error_reporting', E_STRICT);

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';
	
	$q = "SELECT * FROM Properties";
	
	
	$result = $conn->query($q);		

	if (mysqli_num_rows($result) > 0) {
		echo 'accounting_ref,';
		echo 'address,';
		echo 'ct,';
		echo 'lease_current_rate,';
		echo 'lease_expiry_date,';
		echo 'lease_expiry_final,';
		echo 'lease_next_review_display,';
		echo 'lease_number,';
		echo 'lease_ratchet,';
		echo 'lease_rent_reviews,';
		echo 'lease_ror,';
		echo 'lease_term,';
		echo 'lease_type,';
		echo 'leasehold_title,';
		echo '$legal_description,';
		echo 'lessee_correspondence_name,';
		echo 'lessee_correspondence_address,';
		echo 'lessee_correspondence_email,';
		echo 'lessee_correspondence_fax,';
		echo 'lessee_correspondence_mobile,';
		echo 'lessee_correspondence_phone,';
		echo 'lessee_solicitors_address,';
		echo 'lessee_solicitors_email,';
		echo 'lessee_solicitors_fax,';
		echo 'lessee_solicitors_firm,';
		echo 'lessee_solicitors_mobile,';
		echo 'lessee_solicitors_name,';
		echo 'lessee_solicitors_phone,';
		echo 'lessee_solicitors_address,';
		echo 'location,';
		echo 'name,';
		echo 'property_far_rate,';
		echo 'property_file_no,';
		echo 'property_floor_area,';
		echo 'property_floor_area_ratio,';
		echo 'property_land_area_num,';
		echo 'property_land_use,';
		echo 'property_lessee_improvements,';
		echo 'property_locality_factors,';
		echo 'property_site_description,';
		echo 'property_zoning,';
		echo 'rental_payment_comments,';
		echo 'rental_payment_duedates,';
		echo 'rental_payment_frequency,';
		echo 'rental_payment_method,';
		echo 'valuation_market_amount,';
		echo 'valuation_market_amount_per_sq_m,';
		echo 'valuation_market_date_display,';
		echo 'valuation_market_estimated_rent,';
		echo 'valuation_market_firm,';
		echo 'valuation_market_rental_rate_display,';
		echo 'valuation_rating_date,';
		echo 'valuation_rating_improved_value,';
		echo 'valuation_rating_improvements_value,';
		echo 'valuation_rating_land_value';	
		echo "<br>";
		while($row = mysqli_fetch_assoc($result)) {
			echo $row['accounting_ref'] . ",";
			echo $row['address'] . ",";
			echo $row['ct'] . ",";
			echo $row['lease_current_rate'] . ",";
			echo $row['lease_expiry_date'] . ",";
			echo $row['lease_expiry_final'] . ",";
			echo $row['lease_next_review_display'] . ",";
			echo $row['lease_number'] . ",";
			echo $row['lease_ratchet'] . ",";
			echo $row['lease_rent_reviews'] . ",";
			echo $row['lease_ror'] . ",";
			echo $row['lease_term'] . ",";
			echo $row['lease_type'] . ",";
			echo $row['leasehold_title'] . ",";
			echo $row['legal_description'] . ",";
			echo $row['lessee_correspondence_name'] . ",";
			echo $row['lessee_correspondence_address'] . ",";
			echo $row['lessee_correspondence_email'] . ",";
			echo $row['lessee_correspondence_fax'] . ",";
			echo $row['lessee_correspondence_mobile'] . ",";
			echo $row['lessee_correspondence_phone'] . ",";
			echo $row['lessee_solicitors_address'] . ",";
			echo $row['lessee_solicitors_email'] . ",";
			echo $row['lessee_solicitors_fax'] . ",";
			echo $row['lessee_solicitors_firm'] . ",";
			echo $row['lessee_solicitors_mobile'] . ",";
			echo $row['lessee_solicitors_name'] . ",";
			echo $row['lessee_solicitors_phone'] . ",";
			echo $row['lessee_solicitors_address'] . ",";
			echo $row['location'] . ",";
			echo $row['name'] . ",";
			echo $row['property_far_rate'] . ",";
			echo $row['property_file_no'] . ",";
			echo $row['property_floor_area'] . ",";
			echo $row['property_floor_area_ratio'] . ",";
			echo $row['property_land_area_num'] . ",";
			echo $row['property_land_use'] . ",";
			echo $row['property_lessee_improvements'] . ",";
			echo $row['property_locality_factors'] . ",";
			echo $row['property_site_description'] . ",";
			echo $row['property_zoning'] . ",";
			echo $row['rental_payment_comments'] . ",";
			echo $row['rental_payment_duedates'] . ",";
			echo $row['rental_payment_frequency'] . ",";
			echo $row['rental_payment_method'] . ",";
			echo $row['valuation_market_amount'] . ",";
			echo $row['valuation_market_amount_per_sq_m'] . ",";
			echo $row['valuation_market_date_display'] . ",";
			echo $row['valuation_market_estimated_rent'] . ",";
			echo $row['valuation_market_firm'] . ",";
			echo $row['valuation_market_rental_rate_display'] . ",";
			echo $row['valuation_rating_date'] . ",";
			echo $row['valuation_rating_improved_value'] . ",";
			echo $row['valuation_rating_improvements_value'] . ",";
			echo $row['valuation_rating_land_value'];		
			echo "<br>";
	
		}
	}

?>