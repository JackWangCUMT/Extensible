<?php
    // This file supports recurring events, so has a lot of recurrence-specific
    // business logic (read: complexity). If you do not require recurrence, look
    // instead at the much simpler example code in events-basic.php.
    
    require(dirname(__FILE__).'/events-common.php');
    
    // This is a third-party lib that parses the RRULE and generates event instances.
    // See the file header for details.
    require(dirname(__FILE__).'/recur.php');
    
    // This is the maximum number of event instances per master recurring event to generate and return
    // when recurrence is in use. Generally the recurrence pattern defined on each event, in combination
    // with the supplied query date range, should already limit the instances returned to a reasonable
    // range. This value is a saftey check in case something is not specified correctly to avoid the
    // recurrence parser looping forever (e.g., no end date is supplied). This value should accommodate
    // the max realistic combination of events for the supported views and event frequency. For example,
    // by default the maximum frequency is daily, so given a maximum view range of 6 weeks and one
    // instance per day, the minimum required value would be 42. The default of 99 will handle any
    // views supported by Extensible out of the box, but if some custom view range was implemented
    // (e.g. year view) or if the recurrence resolution was increased (e.g., hourly) then you would
    // have to increase this value accordingly.
    $max_event_instances = 99;
    
    $date_format = 'Y-m-d\TH:i:s';
    
    $exception_format = 'Y-m-d\TH:i:s';
    
    //=================================================================================================
    //
    // Event property mappings. The default values match the property names as used in the example
    // data (e.g. Extensible.example.calendar.data.Events) so if you customize the EventModel and/or
    // mappings being used you'd also want to update these properties so that the PHP matches. You
    // should only have to change the string values -- the $ variables are referenced in the PHP code
    // and should not need to be changed.
    //
    //=================================================================================================
    $mappings = array(
        // Basic event properties. This is not all properties, only those needed explicitly
        // while processing recurrence. The others are read and set generically.
        event_id   => 'id',
        start_date => 'start',
        end_date   => 'end',
        duration   => 'duration',
        
        // Recurrence-specific properties needed for processing recurring events:
        rrule                => 'rrule',
        orig_event_id        => 'origid',
        // recur_instance_id    => 'rid',
        recur_edit_mode      => 'redit',
        recur_instance_start => 'ristart',
        
        // Recurrence exceptions
        exdate => 'exdate'
    );
    
    /**
     * Add a recurrence exception date for the given event id
     */
    function addExceptionDate($event_id, $exception_date) {
        global $exception_format, $db;
        
        $exdate = new DateTime($exception_date);
        $exdate = $exdate->format($exception_format);
        
        // Insert only if the event id + exdate doesn't already exist
        $sql = 'INSERT INTO exceptions (event_id, exdate) '.
               'SELECT * FROM (SELECT :event_id, :exdate) AS tmp '.
               'WHERE NOT EXISTS ('.
               '  SELECT * FROM exceptions WHERE event_id = :event_id AND exdate = :exdate'.
               ') LIMIT 1;';
        
        $result = $db->execSql($sql, array(
            ':event_id' => $event_id,
            ':exdate'   => $exdate
        ));
        
        return $result;
    }
    
    function getExceptionDates($event_id) {
        global $db;
        
        $exdates = $db->query('exceptions', array(
            'event_id' => $event_id
        ));
        
        return $exdates;
    }
    
    function removeExceptionDates($event_id) {
        global $db;
        
        $sql = 'DELETE FROM exceptions WHERE event_id = :event_id;';
        
        $result = $db->execSql($sql, array(
            ':event_id' => $event_id
        ));
        
        return $result;
    }
    
    function generateInstances($event, $viewStartDate, $viewEndDate) {
        global $mappings, $date_format, $exception_format, $max_event_instances;
        
        $rrule = $event[$mappings['rrule']];
        $instances = array();
        $counter = 0;
        
        // Get any exceptions for this event. Ideally you would join exceptions
        // to events at the event query level and not have to do a separate select
        // per event like this, but for the demos this is good enough.
        $exceptions = getExceptionDates($event[$mappings['event_id']]);
        
        if (!isset($rrule) || $rrule === '') {
            array_push($instances, $event);
        }
        else {
            $duration = $event[$mappings['duration']];
            
            if (!isset($duration)) {
                // Duration is required to calculate the end date of each instance. You could raise
                // an error here if appropriate, but we'll just be nice and default it to 0 (i.e.
                // same start and end dates):
                $duration = 0;
            }
            
            // Start parsing at the later of event start or current view start:
            $rangeStart = max($viewStartDate, $event[$mappings['start_date']]);
            //$rangeStart = self::adjustRecurrenceRangeStart($rangeStart, $event);
            $rangeStartTime = strtotime($rangeStart);
            
            // Stop parsing at the earlier of event end or current view end:
            $rangeEnd = min($viewEndDate, $event[$mappings['end_date']]);
            $rangeEndTime = strtotime($rangeEnd);
            
            // Third-party recurrence parser -- see recur.php
            $recurrence = new When();
            
            // TODO: Using the event start date here is the correct approach, but is inefficient
            // based on the current recurrence library in use, which does not accept a starting
            // date other than the event start date. The farther in the future the view range is,
            // the more processing is required and the slower performance will be. If you can parse
            // only relative to the current view range, parsing speed is much faster, but it
            // introduces a lot more complexity to ensure that the returned dates are valid for the
            // recurrence pattern. For now we'll sacrifice performance to ensure validity, but this
            // may need to be revisited in the future.
            //$rdates = $recurrence->recur($rangeStart)->rrule($rrule);
            $rdates = $recurrence->recur($event[$mappings['start_date']])->rrule($rrule);
            
            // Counter used for generating simple unique instance ids below
            $idx = 1;
            
            // Loop through all valid recurrence instances as determined by the parser
            // and validate that they are within the valid view range (and not exceptions).
            while ($rdate = $rdates->next()) {
                $rtime = strtotime($rdate->format('c'));
                
                // When there is no end date or maximum count as part of the recurrence RRULE
                // pattern, the parser by default will simply loop until the end of time. For
                // this reason it is critical to have these boundary checks in place:
                if ($rtime < $rangeStartTime) {
                    // Instance falls before the range: skip, but keep trying
                    continue;
                }
                if ($rtime > $rangeEndTime) {
                    // Instance falls after the range: the returned set is sorted in date
                    // order, so we can now exit and return the current event set
                    break;
                }
                
                // Check to see if the current instance date is an exception date
                $exmatch = false;
                foreach ($exceptions as $exception) {
                    if ($exception[$mappings['exdate']] == $rdate->format('Y-m-d H:i:s')) {
                        $exmatch = true;
                        break;
                    }
                };
                if ($exmatch) {
                    // The current instance falls on an exception date so skip it
                    continue;
                }
                
                // Make a copy of the original event and add the needed recurrence-specific stuff:
                $copy = $event;
                
                // On the client side, Ext stores will require a unique id for all returned events.
                // The specific id format doesn't really matter ($mappings['orig_event_id will be used
                // to tie them together) but all ids must be unique:
                $copy[$mappings['event_id']] = $event[$mappings['event_id']].'-rid-'.$idx++;
                
                // Associate the instance to its master event for later editing:
                $copy[$mappings['orig_event_id']] = $event[$mappings['event_id']];
                // Save the duration in case it wasn't already set:
                $copy[$mappings['duration']] = $duration;
                // Replace the series start date with the current instance start date:
                $copy[$mappings['start_date']] = $rdate->format($date_format);
                // By default the master event's end date will be the end date of the entire series.
                // For each instance, we actually want to calculate a proper instance end date from
                // the duration value so that the view can simply treat them as standard events:
                $copy[$mappings['end_date']] = $rdate->add(new DateInterval('PT'.$duration.'M'))->format($date_format);
                
                // Add the instance to the set to be returned:
                array_push($instances, $copy);
                
                if (++$counter > $max_event_instances) {
                    // Should never get here, but it's our safety valve against infinite looping.
                    // You'd probably want to raise an application error if this happens.
                    break;
                }
            }
        }
        return $instances;
    }
    
    /**
     * Returns the duration of the event in minutes
     */
    function calculateDuration($event) {
        global $mappings;
        
        $start = new DateTime($event[$mappings['start_date']]);
        $end = new DateTime($event[$mappings['end_date']]);
        $interval = $start->diff($end);
        
        $minutes = ($interval->days * 24 * 60) +
                   ($interval->h * 60) +
                   ($interval->i);
        
        return $minutes;
    }
    
    /**
     * If the event is recurring, returns the maximum end date
     * supported by PHP so that the event will fall within future
     * query ranges as possibly having matching instances. Note
     * that in a real implementation it would be better to calculate
     * the actual recurrence pattern end date if possible. The current
     * recurrence class used in this example does not make it convenient
     * to do that, so for demo purposes we'll just use the PHP max date.
     * The generateInstances() method will still limit results based on
     * any end date specified, so it will work as expected -- it simply
     * means that in a real-world implementation querying might be slightly
     * less efficient (which does not apply in this example).
     */
    function calculateEndDate($event) {
        global $date_format, $mappings;
        
        $end = $event[$mappings['end_date']];
        $rrule = $event[$mappings['rrule']];
        $isRecurring = isset($rrule) && $rrule !== '';
        
        if ($isRecurring) {
            $max_date = new DateTime('9999-12-31');
            $recurrence = new When();
            $recurrence->rrule($rrule);
            
            if (isset($recurrence->end_date) && $recurrence->end_date < $max_date) {
                $end = $recurrence->end_date->format($date_format).'Z';
            }
            else if (isset($recurrence->count) && $recurrence->count > 0) {
                $count = 0;
                $newEnd;
                $rdates = $recurrence->recur($event[$mappings['start_date']])->rrule($rrule);
                
                while ($rdate = $rdates->next()) {
                    $newEnd = $rdate;
                    if (++$count > $recurrence->count) {
                        break;
                    }
                }
                // The 'minutes' portion should match Extensible.calendar.data.EventModel.resolution:
                $newEnd->modify('+'.$event[$mappings['duration']].' minutes');
                $end = $newEnd->format($date_format).'Z';
            }
            else {
                // default to max date if nothing else
                $end = date($date_format, PHP_INT_MAX).'Z';
            }
        }
        return $end;
    }
    
    /**
     * Remove any extra attributes that are not mapped to db columns for persistence
     * otherwise MySQL will throw an error
     */
    function cleanEvent($event) {
        global $mappings;
        
        unset($event[$mappings['orig_event_id']]);
        unset($event[$mappings['recur_edit_mode']]);
        unset($event[$mappings['recur_instance_start']]);
        
        return $event;
    }
    
    /**
     * Return a single, non-recurring copy of an event with no id
     */
    function createSingleCopy($event) {
        global $mappings;
        
        $copy = $event;
        
        unset($copy[$mappings['event_id']]);
        unset($copy[$mappings['rrule']]);
        
        return addEvent($copy);
    }
    
    function addEvent($event) {
        global $db, $mappings;
        
        $rrule = $event[$mappings['rrule']];
        $isRecurring = isset($rrule) && $rrule !== '';
        
        if ($isRecurring) {
            // If this is a recurring event, first calculate the duration between
            // the start and end datetimes so that each recurring instance can
            // be properly calculated.
            $event[$mappings['duration']] = calculateDuration($event);
            
            // Now that duration is set, we have to update the event end date to
            // match the recurrence pattern end date (or max date if the recurring
            // pattern does not end) so that the stored record will be returned for
            // any query within the range of recurrence.
            $event[$mappings['end_date']] = calculateEndDate($event);
        }
        
        return $db->insert('events', cleanEvent($event));
    }
    
    /**
     * Helper method that updates the UNTIL portion of a recurring event's RRULE
     * such that the passed end date becomes the new UNTIL value. It handles updating
     * an existing UNTIL value or adding it if needed so that there is only one
     * unqiue UNTIL value when this method returns.
     */
    function endDateRecurringSeries($event, $endDate) {
        global $date_format, $mappings;
        
        $event[$mappings['end_date']] = $endDate->format('c');
        
        $parts = explode(';', $event[$mappings['rrule']]);
        $newRrule = array();
        $untilFound = false;
        
        foreach ($parts as $part) {
            if (strrpos($part, 'UNTIL=') === false) {
                array_push($newRrule, $part);
            }
        }
        array_push($newRrule, 'UNTIL='.$endDate->format($date_format).'Z');
        $event[$mappings['rrule']] = implode(';', $newRrule);
        
        return $event;
    }
    
    /**
     * Destroy the event, or possibly add an exception for a recurring instance
     */
    function deleteEvent($event) {
        global $db, $mappings;
        
        $editMode = $event[$mappings['recur_edit_mode']];
        
        if ($editMode) {
            // This is a recurring event, so determine how to handle it.
            // First, load the original master event for this instance:
            $master_event = $db->select('events', $event[$mappings['orig_event_id']]);
            // Select by id returns an array of one item, so grab it
            $master_event = $master_event[0];
            $event_id = $master_event[$mappings['event_id']];
            
            switch ($editMode) {
                case 'single':
                    // Not actually deleting, just adding an exception so that this
                    // date instance will no longer be returned in queries:
                    addExceptionDate($event_id, $event[$mappings['recur_instance_start']]);
                    break;
                    
                case 'future':
                    // Not actually deleting, just updating the series end date so that
                    // any future dates beyond the edited date will no longer be returned.
                    // Use this instance's start date as the new end date of the master event:
                    $endDate = new DateTime($event[$mappings['start_date']]);
                    $endDate->modify('-1 second');
                    
                    // Now update the RRULE with this new end date also:
                    $master_event = endDateRecurringSeries($master_event, $endDate);
                    
                    $db->update('events', cleanEvent($master_event));
                    break;
                    
                case 'all':
                    // Actually destroy the master event and remove any existing exceptions
                    $db->delete('events', $event_id);
                    removeExceptionDates($event_id);
                    break;
            }
        }
        else {
            // This is a plain old non-recurring event, nuke it
            $event_id = $event[$mappings['event_id']];
            $db->delete('events', $event_id);
            removeExceptionDates($event_id);
        }
        
        return $event_id;
    }
    
    /**
     * Update an event or recurring series
     */
    function updateEvent($event) {
        global $db, $mappings;
        
        $editMode = $event[$mappings['recur_edit_mode']];
        
        if ($editMode) {
            // This is a recurring event, so determine how to handle it.
            // First, load the original master event for this instance:
            $master_event = $db->select('events', $event[$mappings['orig_event_id']]);
            // Select by id returns an array of one item, so grab it
            $master_event = $master_event[0];
            $event_id = $master_event[$mappings['event_id']];
            
            switch ($editMode) {
                // NOTE: Both the "single" and "future" cases currently create new
                // events in order to represent edited versions of recurrence instances.
                // This could more flexibly be handled within a single master
                // event by supporting multiple RRULE and EXRULE definitions per
                // event. This would be a bit more complex to implement and would
                // also require more processing code to arrive at the final event
                // series to return when querying. For this example (which is already
                // complex enough) we're sticking to the simpler edit implementations
                // which can simply go through the existing default event logic, but
                // you are free to implement these however you'd like in real projects.
                
                case 'single':
                    // Create a new event based on the data passed in (the
                    // original event does not need to be updated in this case):
                    createSingleCopy($event);
                    // Add an exception for the original occurrence start date
                    // so that the original instance will not be displayed:
                    addExceptionDate($event_id, $event[$mappings['recur_instance_start']]);
                    break;
                    
                case 'future':
                    // In this sample code we're going to split the original event
                    // into two: the original up to the edit date and a new event
                    // from the edit date to the series end date. Because of this we
                    // only end-date the original event, don't update it otherwise.
                    // This could be done all within a single event as explained in
                    // the comments above, but for this example we're keeping it simple.
                    
                    // First create the new event for the updated future series.
                    // Update the recurrence start dates to the edited date:
                    $event[$mappings['recur_instance_start']] = $event[$mappings['start_date']];
                    // Don't reuse the existing instance id since we're creating a new event:
                    unset($event[$mappings['event_id']]);
                    // Overwrite the instance end date with the master (series) end date:
                    $event[$mappings['end_date']] = $master_event[$mappings['end_date']];
                    // Create the new event (which also persists it). Note that we are NOT calling
                    // addEvent() here, which recalculates the end date for recurring events. In this
                    // case we always want to keep the existing master event end date.
                    $event = $db->insert('events', cleanEvent($event));
                    
                    // Now we can update the original event to end at the instance start
                    $endDate = new DateTime($event[$mappings['recur_instance_start']]);
                    // End-date for the original event:
                    $endDate->modify('-1 second');
                    // End-date the RRULE for the master event to the instance start:
                    $master_event = endDateRecurringSeries($master_event, $endDate);
                    // Persist changes:
                    $db->update('events', $master_event);
                    break;
                    
                case 'all':
                    // This is the simplest case to handle since there is no need to
                    // split the event or deal with exceptions -- we're just updating
                    // the original master event. Make sure the id is the original id,
                    // not the recurrence instance id:
                    $event[$mappings['event_id']] = $event_id;
                    // Base duration off of the current instance start / end since the end
                    // date for the series will be some future date:
                    $event[$mappings['duration']] = calculateDuration($event);
                    // In case the start date was edited by the user, we need to recalculate
                    // the updated start date based on the time difference between the original
                    // and current values:
                    $instanceStart = new DateTime($event[$mappings['recur_instance_start']]);
                    $newStart = new DateTime($event[$mappings['start_date']]);
                    $diff = $instanceStart->diff($newStart);
                    // Now apply the diff timespan to the original start date:
                    $origStart = new DateTime($master_event[$mappings['start_date']]);
                    $event[$mappings['start_date']] = $origStart->add($diff)->format('c');
                    // Finally update the end date to the original series end date. This is
                    // important in the case where the series may have been split previously
                    // (e.g. by a "future" edit) so we want to preserve that:
                    //$attr[Event::$end_date] = $rec->attributes[Event::$end_date];
                    $event[$mappings['end_date']] = calculateEndDate($event);
                    // Persist our changes:
                    $event = $db->update('events', cleanEvent($event));
                    break;
            }
        }
        else {
            // No recurrence, so just do a simple update 
            if ($event[$mappings['rrule']]) {
                // There was no recurrence edit mode, but there is an rrule, so this was
                // an existing non-recurring event that had recurrence added to it. Need
                // to calculate the duration and end date for the series.
                $event[$mappings['duration']] = calculateDuration($event);
                $event[$mappings['end_date']] = calculateEndDate($event);
            }
            
            $event = $db->update('events', cleanEvent($event));
        }
        
        return $event;
    }
    
    
    //********************************************************************************
    //
    // Finally, process the request
    //
    //********************************************************************************
    
    switch ($action) {
        case 'load':
            $start_dt = isset($_REQUEST['startDate']) ? strtolower($_REQUEST['startDate']) : null;
            $end_dt = isset($_REQUEST['endDate']) ? strtolower($_REQUEST['endDate']) : null;
            
            if (isset($start_dt) && isset($end_dt)) {
                // Query by date range for displaying a calendar view
                $sql = 'SELECT * FROM events WHERE app_id = :app_id'.
                        ' AND ((start >= :start AND start <= :end)'.  // starts in range
                        ' OR (end >= :start AND end <= :end)'.        // ends in range
                        ' OR (start <= :start AND end >= :end));';    // spans range
                
                $events = $db->querySql($sql, array(
                    ':app_id' => $app_id,
                    ':start'  => $start_dt,
                    ':end'    => $end_dt
                ));
                
                $matches = array();
                
                foreach ($events as $event) {
                    $matches = array_merge($matches, generateInstances($event, $start_dt, $end_dt));
                }
                out($matches);
            }
            else {
                out(null, 'You must supply a valid start and end date');
            }
            break;

        case 'add':
            if (isset($event)) {
                $result = addEvent($event);
            }
            out($result);
            break;
        
        case 'update':
            if (isset($event)) {
                $result = updateEvent($event);
            }
            out($result);
            break;
        
        case 'delete':
            if (isset($event)) {
                $result = deleteEvent($event);
            }
            if ($result === 1) {
                // Return the deleted id instead of row count
                $result = $event['id'];
            }
            out($result);
            break;
    }
