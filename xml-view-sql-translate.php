<?php

$xml_input = 'calculation_view.xml'; // Your XML file path here

// Load the XML content from the file
$xmlString = file_get_contents($xml_input);

// Check if the XML file was loaded successfully
if ($xmlString === false) {
    die("Error: Unable to load XML file.");
}

// Regex to remove inner double quotes in any leftInput or rightInput attributes inside <join> tags
// This fixes improperly formatted XML
$fixedXmlString = preg_replace('/(leftInput|rightInput)="([^"]+)"([^"]+)"\.([^"]+)"/', '$1="$2$3.$4"', $xmlString);

try {
    // Parse the cleaned XML string using SimpleXMLElement
    $xml = new SimpleXMLElement($fixedXmlString);

    // Save the modified XML to a new file
    $outputFile = 'cleaned_xml_file.xml'; // Specify the output file path
    $xml->asXML($outputFile);

    // Output a success message
    echo "XML parsed and cleaned successfully! New file saved as: $outputFile\n";

    // Extract the 'name' attribute from the <View:ColumnView> block for use as part of the SQL file name
    $view_name = (string)$xml->attributes()["name"];
    $output_file = $view_name . '.sql'; // Set the output SQL file name
} catch (Exception $e) {
    // Handle any exceptions during XML parsing
    echo 'Error: ' . $e->getMessage();
}

$xml_output = ""; // Initialize the output string for SQL

$select = array(); // Initialize array to store SQL select statements
$nodes = $xml->viewNode; // Get the viewNode elements from the XML

// Loop through each view node
foreach ($nodes as $node) {
    $name = "x_" . $node->attributes()["name"] . ""; // Name each node based on its XML name attribute
    $type = ""; // Initialize type of node (projection, join, etc.)
    $select_fields = ""; // Initialize fields to be selected
    $select_from = ""; // Initialize the FROM clause
    $select_where = ""; // Initialize the WHERE clause
    $select_options = ""; // Initialize additional query options (GROUP BY, ORDER BY)

    // Determine the type of node based on its xsi:type attribute
    if ($node->attributes("xsi", TRUE)->type == "View:Projection") {
        $type = "projection";
    } elseif ($node->attributes("xsi", TRUE)->type == "View:JoinNode") {
        $type = "join";
    } elseif ($node->attributes("xsi", TRUE)->type == "View:Aggregation") {
        $type = "aggregation";
    } elseif ($node->attributes("xsi", TRUE)->type == "View:Rank") {
        $type = "rank";
    }

    // For join nodes, set the table alias (prefix) for fields
    if ($type == "join") {
        $quelle = "x_" . substr($node->input[0]->viewNode, 3) . ".";
    } else {
        $quelle = "";
    }

    # ************ selection fields from main/first table ************
    $felder = $node->input[0]->children(); // Get children elements (fields)
    $i = 0; // Initialize counter
    $aggregierung = ''; // Initialize aggregation behavior

    // Loop through the fields in the first table and build the SELECT clause
    foreach ($felder as $a) {
        if ($a->attributes()["sourceName"]) {
            if ($i != 0) $select_fields .= " , "; // Add a comma separator between fields
            // Check if the field has an aggregation behavior (e.g., SUM)
            if ($node->element[$i]->attributes()["aggregationBehavior"]) {
                $aggregierung = $node->element[$i]->attributes()["aggregationBehavior"];
                if ($aggregierung == "SUM") $select_fields .= "SUM("; // Add SUM if aggregation is SUM
            }
            // Add the source field to the SELECT clause
            $select_fields .= $quelle . $a->attributes()["sourceName"];
            if ($aggregierung == "SUM") $select_fields .= ")"; // Close SUM aggregation
            $select_fields .= " as " . $a->attributes()["targetName"]; // Add the alias for the field
            $aggregierung = ""; // Reset aggregation behavior
            $i++; // Increment field counter
        }
    }

    # ************ selection fields from second table (for joins) ************
    if ($type == "join") {
        $felder = $node->input[1]->children(); // Get fields from the second table (join)
        $quelle = "x_" . substr($node->input[1]->viewNode, 3) . "."; // Set alias for second table
        $i = 0;

        // Loop through the fields in the second table
        foreach ($felder as $a) {
            if ($a->attributes()["sourceName"]) {
                $select_fields .= " , "; // Add comma separator
                $select_fields .= $quelle . $a->attributes()["sourceName"]; // Add the field to the SELECT clause
                $select_fields .= " as " . $a->attributes()["targetName"]; // Add the alias for the field
                $i++; // Increment field counter
            }
        }
    }

    # ************ extra fields from formulas ************
    // If the node type is projection, aggregation, or join, handle additional fields calculated via formulas
    if ($type == "aggregation" or $type == "projection" or $type == "join") {
        $felder = $node->element; // Get elements (fields)
        foreach ($felder as $a) {
            if ($a->calculationDefinition) {
                $select_fields .= " , "; // Add comma separator
                $formular = $a->calculationDefinition->formula; // Get formula definition

                // Handle INTEGER types directly
                if ($a->inlineType->attributes()["name"] == "INTEGER") {
                    $select_fields .= $formular;
                }
                // Handle 'if' formulas more efficiently (convert to CASE statements)
                elseif (substr($formular, 0, 2) == "if") {
                    $formular = html_entity_decode($formular); // Decode HTML entities
                    $formular = str_replace(['if(', ')'], '', $formular); // Strip 'if(' and closing parentheses
                    $parts = explode(',', $formular); // Split formula by commas

                    // Build CASE statement for the 'if' condition
                    $select_fields .= "CASE ";
                    for ($i = 0; $i < count($parts) - 1; $i += 2) {
                        if (isset($parts[$i]) && isset($parts[$i + 1])) {
                            $condition = trim($parts[$i]); // Get condition
                            $result = trim($parts[$i + 1]); // Get result
                            $select_fields .= "WHEN $condition THEN $result "; // Add WHEN and THEN parts
                        }
                    }

                    // Handle ELSE case
                    $else_part = isset($parts[$i]) ? trim($parts[$i]) : "'Delivered But Partially Invoiced'";
                    $select_fields .= "ELSE $else_part END"; // Complete CASE statement
                }
                // Handle other formula types (e.g., LEFT, RIGHT string functions)
                else {
                    $formular = str_replace(["rightstr", "leftstr", '"'], ["RIGHT", "LEFT", ""], $formular); // Replace string functions
                    $select_fields .= $formular; // Add formula to the SELECT clause
                }

                $select_fields .= " as " . $a->attributes()["name"]; // Add alias for the field
                $i++; // Increment field counter
            }
        }
    }
    // Handle rank nodes, constructing RANK() OVER partition clause
    elseif ($type == "rank") {
        $partitionElement = explode("/", $node->windowFunction->partitionElement); // Get partition element
        $order = explode("/", $node->windowFunction->children()[1]->attributes()[0]); // Get order element
        $rankElement = explode("/", $node->windowFunction->rankElement); // Get rank element
        $select_fields .= " , RANK() OVER ("; // Start RANK function
        $select_fields .= "PARTITION BY " . $partitionElement[3]; // Add partition clause
        $select_fields .= " ORDER BY " . $order[3]; // Add order clause
        $select_fields .= ") as " . $rankElement[3]; // Add alias for the rank
    }

    # ************ FROM clause ************
    // Handle FROM clause based on the type of node
    if ($type == "projection" or $type == "aggregation" or $type == "rank") {
        if (substr($node->input->entity, 3)) {
            $teile = explode(".", substr($node->input->entity, 3)); // Split entity name
            if ($teile[0] == '"ABAP"') {
                $select_from = str_replace('"ABAP"', '"SAPABAP1"', substr($node->input->entity, 3)) . '"'; // Replace ABAP schema if necessary
            } else {
                $select_from = '"' . substr($node->input->entity, 3) . '"'; // Otherwise, use the entity name directly
            }
            $select_from = str_replace('".', '"."', $select_from); // Fix any misplaced quotes
            $select_from = str_replace('""', '"', $select_from); // Remove any double quotes
        } else {
            $select_from = '(' . PHP_EOL . $select["x_" . substr($node->input->viewNode, 3)] . ' )'; // Handle nested subqueries
        }
    }
    // For join nodes, handle the JOIN clause
    elseif ($type == "join") {
        // Extract first and second partners for the join
        $first_partner = "x_" . substr($node->input[0]->viewNode, 3);
        $second_partner = "x_" . substr($node->input[1]->viewNode, 3);

        // Debugging: Output the values of $first_partner and $second_partner
        // echo "First Partner: " . $first_partner . PHP_EOL;
        // echo "Second Partner: " . $second_partner . PHP_EOL;

        // Handle join type (leftOuter, rightOuter, inner, or generic JOIN)
        $join_type = $node->join->attributes()["joinType"];
        if ($join_type == "leftOuter") {
            $join_type = "LEFT OUTER JOIN";
        } elseif ($join_type == "rightOuter") {
            $join_type = "RIGHT OUTER JOIN";
        } elseif ($join_type == "inner") {
            $join_type = "INNER JOIN";
        } else {
            $join_type = "JOIN";
        }

        // Build the FROM clause with join
        $select_from = "((" . PHP_EOL . $select[$first_partner] . PHP_EOL . ")";
        $select_from .= " " . $first_partner . PHP_EOL;
        $select_from .= $join_type . PHP_EOL;
        $select_from .= "(" . PHP_EOL . $select[$second_partner] . PHP_EOL . ")";
        $select_from .= " " . $second_partner . PHP_EOL;
        $select_from .= "ON " . PHP_EOL;

        // Build the ON clause with join conditions
        $felder = $node->join->children(); // Get join conditions
        $middle = count($felder) / 2;
        for ($i = 0; $i < count($felder) / 2; $i++) {
            if ($i != 0) $select_from .= " AND "; // Add AND between conditions
            $select_from .= $first_partner . "." . $felder[$i] . " = " . $second_partner . "." . $felder[$i + $middle]; // Build condition
        }

        $select_from .= " )"; // Close the join clause
    }

    # ************ WHERE clause ************
    // Build the WHERE clause based on filters and expressions
    $i = 0;
    $felder = $node->elementFilter; // Get filter elements
    foreach ($felder as $a) {
        if ($i == 0) $select_where = PHP_EOL . "WHERE "; // Start WHERE clause
        else $select_where .= " AND "; // Add AND between conditions

        // Get filter field and its attributes
        $filterfeld = $a->attributes()["elementName"];
        $operator = $a->valueFilter->attributes()["operator"];
        $including = $a->valueFilter->attributes()["including"];

        // Handle different filter operators (IN, CP, BT, etc.)
        if ($operator == "IN") {
            $select_where .= $filterfeld . " IN (";
            $v2 = 0;
            foreach ($a->valueFilter->operands as $v) {
                if ($v2 != 0) $select_where .= " , "; // Add comma separator
                $wert = $v->attributes()["value"]; // Get value
                $select_where .= "'" . $wert . "'"; // Add value to IN clause
                $v2++;
            }
            $select_where .= ")";
        } elseif ($operator == "CP") {
            $select_where .= "contains(" . $filterfeld . ", '" . $a->valueFilter->attributes()["value"] . "')"; // Handle CP (contains) operator
        } elseif ($operator == "BT") {
            $select_where .= $filterfeld . " BETWEEN '" . $a->valueFilter->attributes()["lowValue"] . "' AND '" . $a->valueFilter->attributes()["highValue"] . "'"; // Handle BETWEEN operator
        } elseif ($including == "true") {
            $select_where .= $filterfeld . " = '" . $a->valueFilter->attributes()["value"] . "'"; // Handle equality filter
        } elseif ($including == "false") {
            $select_where .= $filterfeld . " <> '" . $a->valueFilter->attributes()["value"] . "'"; // Handle inequality filter
        }
        $i++; // Increment filter counter
    }
    // Handle additional filter expressions
    $felder = $node->filterExpression; // Get filter expressions
    foreach ($felder as $a) {
        if ($i == 0) $select_where = PHP_EOL . "WHERE "; // Start WHERE clause
        else $select_where .= " AND "; // Add AND between expressions

        // Process filter expression
        $filterfeld = $a->formula; // Get formula
        if (substr($filterfeld, 0, 5) == "(in (") {
            $result = explode('"', $filterfeld); // Split formula
            $in_string = $result[2];
            $in_string = preg_replace('/,/', '', $in_string, 1); // Remove comma
            $in_string = substr($in_string, 0, -1); // Clean up the string
            $select_where .= $result[1] . " IN (" . $in_string; // Build IN clause
        } else {
            $select_where .= $filterfeld; // Add other formula expressions to the WHERE clause
        }
        $i++; // Increment filter counter
    }

    # ************ extra options - GROUP BY ************
    // Handle GROUP BY clause for aggregation nodes
    if ($type == "aggregation") {
        $i = 0;
        $felder = $node->element; // Get aggregation elements
        foreach ($felder as $a) {
            if (!$a->attributes()["aggregationBehavior"]) {
                if ($i == 0) $select_options = PHP_EOL . "GROUP BY "; // Start GROUP BY clause
                else $select_options .= " , "; // Add comma separator
                $select_options .= $a->attributes()["name"]; // Add field to GROUP BY clause
                $i++; // Increment field counter
            }
        }
    }

    # ************ Finalize SELECT statement ************
    $select_text = "SELECT"; // Start SELECT clause
    $select_text .= PHP_EOL . $select_fields; // Add fields
    $select_text .= PHP_EOL . 'FROM'; // Add FROM clause
    $select_text .= PHP_EOL . $select_from; // Add table(s)
    $select_text .= $select_where; // Add WHERE clause (if any)
    $select_text .= $select_options; // Add GROUP BY or other options (if any)

    // Store the SELECT statement in the $select array
    $select[$name] = $select_text;
    $last_name = $name; // Store the last node name
}

// Determine final output name
if ($xml_output == "") {
    $xml_output = $last_name;
}
$result = $select[$xml_output]; // Get the final SELECT statement

// Write the final SQL to a file
$file_written = file_put_contents($output_file, $result);

// Check if the file was written successfully
if ($file_written === false) {
    echo "Failed to write SQL to file.";
} else {
    echo "SQL query has been written to $output_file";
}
