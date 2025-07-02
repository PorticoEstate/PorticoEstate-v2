#!/bin/bash

# Extract ID configurations for all registry types
echo "Registry Type ID Configurations from class.sogeneric.inc.php:"
echo "============================================================"

# Function to extract registry info
extract_registry_info() {
    local registry_type="$1"
    echo -n "$registry_type: "
    
    # Find the case line and extract the next few lines to get the ID config
    awk "
        /case '$registry_type':/ { found=1; next }
        found && /'id'.*array/ { 
            gsub(/.*'type' => '/, \"\")
            gsub(/'.*/, \"\")
            print \$0
            found=0
        }
    " /var/www/html/src/modules/property/inc/class.sogeneric.inc.php
}

# Extract all the registry types we need
registries=(
    "part_of_town"
    "district" 
    "street"
    "dimb"
    "dimd"
    "periodization"
    "tax"
    "voucher_cat"
    "voucher_type" 
    "tender_chapter"
    "owner_cats"
    "tenant_cats"
    "vendor_cats"
    "vendor"
    "owner"
    "tenant"
    "s_agreement"
    "tenant_claim"
    "wo_hours"
    "r_condition_type"
    "r_probability"
    "r_consequence"
    "authorities_demands"
    "b_account"
    "b_account_category"
    "dimb_role"
    "condition_survey_status"
    "request_responsible_unit"
    "ticket_priority"
    "external_com_type"
)

for registry in "${registries[@]}"; do
    extract_registry_info "$registry"
done
