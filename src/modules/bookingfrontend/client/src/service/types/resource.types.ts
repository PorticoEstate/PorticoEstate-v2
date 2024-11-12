interface IResource {
    id: number;
    name: string;
    activity_id: number | null;
    active: number;
    sort: number | null;
    organizations_ids: string | null;
    json_representation: any | null; // Consider using a more specific type if the structure is known
    rescategory_id: number | null;
    opening_hours: string | null;
    contact_info: string | null;
    direct_booking: number | null;
    booking_day_default_lenght: number | null;
    booking_dow_default_start: number | null;
    booking_time_default_start: number | null;
    booking_time_default_end: number | null;
    simple_booking: number | null;
    direct_booking_season_id: number | null;
    simple_booking_start_date: number | null;
    booking_month_horizon: number | null;
    simple_booking_end_date: number | null;
    booking_day_horizon: number | null;
    capacity: number | null;
    deactivate_calendar: number;
    deactivate_application: number;
    booking_time_minutes: number | null;
    booking_limit_number: number | null;
    booking_limit_number_horizont: number | null;
    hidden_in_frontend: number | null;
    activate_prepayment: number | null;
    booking_buffer_deadline: number | null;
    building_id: number | null;
    description_json:string;
}

