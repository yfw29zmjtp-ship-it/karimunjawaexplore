<?php
/**
 * Business Configuration
 * Defines all businesses with separate databases
 */

$BUSINESSES = [
    [
        'id' => 1,
        'name' => 'Explore Karimunjawa',
        'database' => 'karw6956_explore',
        'type' => 'travel_bureau',
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
