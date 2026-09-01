<?php
/**
 * Business Configuration
 * Defines all businesses with separate databases
 */

$BUSINESSES = [
$BUSINESSES = [
    [
        'id' => 1,
        'name' => "Ben's Cafe",
        'database' => 'adf_benscafe',
        'type' => 'restaurant',
        'active' => true
    ],
    [
        'id' => 2,
        'name' => 'Hotel',
        'database' => 'adf_narayana_hotel',
        'type' => 'hotel',
        'active' => true
    ],
    [
        'id' => 3,
        'name' => 'Eat & Meet Restaurant',
        'database' => 'adf_eat_meet',
        'type' => 'restaurant',
        'active' => false
    ],
    [
        'id' => 4,
        'name' => 'Pabrik Kapal',
        'database' => 'adf_pabrik_kapal',
        'type' => 'manufacture',
        'active' => false
    ],
    [
        'id' => 5,
        'name' => 'Furniture',
        'database' => 'adf_furniture',
        'type' => 'retail',
        'active' => false
    ],
    [
        'id' => 6,
        'name' => 'Karimunjawa Tourism',
        'database' => 'adf_karimunjawa',
        'type' => 'tourism',
        'active' => false
    ],
    [
        'id' => 7,
        'name' => 'CQC',
        'database' => 'adf_cqc',
        'type' => 'other',
        'active' => true
    ]
];

// Helper function to get business by ID
function getBusinessById($id) {
    global $BUSINESSES;
    foreach ($BUSINESSES as $business) {
        if ($business['id'] == $id) {
            return $business;
        }
    }
    return null;
}

// Helper function to get business by database name
function getBusinessByDatabase($dbName) {
    global $BUSINESSES;
    foreach ($BUSINESSES as $business) {
        if ($business['database'] == $dbName) {
            return $business;
        }
    }
    return null;
}

// Helper function to get all active businesses
function getActiveBusinesses() {
    global $BUSINESSES;
    return array_filter($BUSINESSES, function($b) {
        return $b['active'] === true;
    });
}
