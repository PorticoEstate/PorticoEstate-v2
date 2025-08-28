import {phpGWLink} from "../../helpers/util";
import {
    getSearchDateString,
    getIsoDateString,
} from "./search-util";

import './info-cards/event-info-card'

class EventSearch {
    text = ko.observable("");
    from_date = ko.observable(getSearchDateString(new Date()));
    // from_date = ko.observable(getSearchDateString(new Date(2021, 1, 1)));
    to_date = ko.observable(getSearchDateString(new Date(new Date().getTime() + (7 * 86400 * 1000))));
    events = ko.observableArray([]);
    result_shown = ko.observable(25);
    transCallable = ko.computed(function() {
        if(globalThis['translations'] && globalThis['translations']()) {}
        return trans;
    });

    constructor() {
        this.from_date.subscribe(from => {
            console.log("FROM", from);
            this.fetchEventOnDates();
        })

        this.to_date.subscribe(to => {
            console.log("TO", to);
            this.fetchEventOnDates();
        })

        this.fetchEventOnDates();
        window.addEventListener('scroll', this.handleScroll.bind(this));

    }

    handleScroll() {
        const bottomOfWindow = window.scrollY + window.innerHeight >= document.documentElement.scrollHeight;
        if (bottomOfWindow && this.result_shown() < this.result().length) {
            this.result_shown(this.result_shown() + 25);
        }
    }
    isValidDate(dateArray) {
        if (!dateArray || dateArray.length !== 3) {
            return false;
        }
        
        const [day, month, year] = dateArray;
        
        // Check if all parts are valid numbers
        if (isNaN(parseInt(day)) || isNaN(parseInt(month)) || isNaN(parseInt(year))) {
            return false;
        }
        
        // Check if year has 4 digits
        if (year.length !== 4) {
            return false;
        }
        
        // Create a date object and check if it's valid
        const date = new Date(parseInt(year), parseInt(month) - 1, parseInt(day));
        return date instanceof Date && !isNaN(date) && 
               date.getDate() === parseInt(day) && 
               date.getMonth() === parseInt(month) - 1 && 
               date.getFullYear() === parseInt(year);
    }
    
    fetchEventOnDates() {
        const from = this.from_date()?.split(".");
        const to = this.to_date()?.split(".");
        
        // Validate dates before proceeding
        const fromIsValid = this.isValidDate(from);
        const toIsValid = this.isValidDate(to);
        
        if (!fromIsValid) {
            console.log("Invalid from date, using current date instead");
            this.from_date(getSearchDateString(new Date()));
            return; // Exit and wait for the subscription to trigger again with valid date
        }
        
        if (!toIsValid && to && to.length > 0) {
            console.log("Invalid to date, using from date + 7 days instead");
            const fromDate = new Date(parseInt(from[2]), parseInt(from[1]) - 1, parseInt(from[0]));
            const sevenDaysLater = new Date(fromDate.getTime() + (7 * 86400 * 1000));
            this.to_date(getSearchDateString(sevenDaysLater));
            return; // Exit and wait for the subscription to trigger again with valid date
        }
        
        // Double-check dates again in case they've been modified
        const recheckedFrom = this.from_date()?.split(".");
        const recheckedTo = this.to_date()?.split(".");
        
        if (!this.isValidDate(recheckedFrom) || !this.isValidDate(recheckedTo)) {
            console.log("Dates still invalid after correction, aborting search");
            return; // Don't proceed with the search if dates are still invalid
        }
        
        const fromDate = `${recheckedFrom[2]}-${recheckedFrom[1]}-${recheckedFrom[0]}T00:00:00`; // year-month-day
        const toDate = `${recheckedTo[2]}-${recheckedTo[1]}-${recheckedTo[0]}T23:59:59`;
        const buildingID = "";
        const facilityTypeID = "";
        const start = 0;
        const end = 1000;
        const loggedInOrgs = "";
        const url = phpGWLink('bookingfrontend/', {
            menuaction: 'bookingfrontend.uieventsearch.upcoming_events',
            fromDate,
            toDate,
            buildingID,
            facilityTypeID,
            loggedInOrgs,
            start,
            end,
            length: -1
        }, true);
        
        // Only make the AJAX call if both dates are properly formatted
        $.ajax({
            url,
            success: response => {
                this.events(response);
            },
            error: error => {
                console.log(error);
            }
        })
    }

    reset() {
        this.text("");
        this.from_date(getSearchDateString(new Date()))
        this.to_date("");
    }



//     addInfoCards(el, events, count) {
//         const append = [];
//         for (const event of events) {
//             append.push(`
//     <div class="col-12 mb-4">
//       <div class="js-slidedown slidedown">
//         <button class="js-slidedown-toggler slidedown__toggler" type="button" aria-expanded="false">
//           <span>${event.event_name}</span>
//           <span class="slidedown__toggler__info">
//           ${joinWithDot([
//                 event.location_name,
//                 getSearchDatetimeString(new Date(event.from)) + " - " + ((new Date(event.from)).getDate() === (new Date(event.to)).getDate() ? getSearchTimeString(new Date(event.to)) : getSearchDatetimeString(new Date(event.to)))])}
//           </span>
//         </button>
//         <div class="js-slidedown-content slidedown__content">
//           <p>
//             ${event.location_name}
//             <ul>
//                 <li>Fra: ${event.from}</li>
//                 <li>Til: ${event.to}</li>
//             </ul>
//           </p>
//         </div>
//       </div>
//     </div>
// `
//             )
//         }
//         el.append(append.join(""));
//         fillSearchCount(events, count);
//     }
    result = ko.computed(() => {
        console.log("rescall")

        if(!this.events()) {
            return []
        }
        this.result_shown(25)
        let events = this.events();
        if (this.text() !== "") {
            const re = new RegExp(this.text(), 'i');
            events = this.events().filter(o => o.event_name.match(re) || o.location_name.match(re))
        }
        console.log(events)
        return events;
        // this.addInfoCards(el, events, count);

    })

    resLength = ko.computed(() => {
        // const maxCount = 1337
        const maxCount = this.result().length;
        // const currentResults = this.result_shown() > maxCount ? maxCount : this.result_shown();
        // return `Antall treff: ${currentResults} av ${maxCount}`
        return `Antall treff: ${maxCount}`
    })
}


ko.components.register('event-search', {
    viewModel: EventSearch,
    // language=HTML
    template: `
        <div id="search-event">
            <div class="bodySection">
                <div class="multisearch w-100 mb-5">
                    <div class="multisearch__inner w-100">
                        <div class="row flex-column flex-md-row">
                            <div class="col mb-3 mb-md-0">
                                <div class="multisearch__inner__item">
                                    <label for="search-event-text">
                                        <trans>common:search</trans>
                                    </label>
                                    <input id="search-event--text" type="text"
                                           data-bind="textInput: text, attr: {'placeholder': transCallable()('bookingfrontend','event_building')}"/>
                                </div>
                            </div>
                            <div class="col mb-3 mb-md-0 multisearch__inner--border">
                                <div class="multisearch__inner__item">
                                    <label for="search-event-datepicker-from">
                                        <trans params="{tag: 'from_date', group: 'bookingfrontend'}"></trans>
                                    </label>
                                    <input type="text" id="search-event-datepicker-from" class="js-basic-datepicker"
                                           placeholder="dd.mm.yyyy" data-bind="textInput: from_date, datepicker"/>
                                </div>
                            </div>
                            <div class="col mb-3 mb-md-0 multisearch__inner--border">
                                <div class="multisearch__inner__item">
                                    <label for="search-event-datepicker-to">
                                        <trans params="{tag: 'to_date', group: 'bookingfrontend'}"></trans>
                                    </label>
                                    <input type="text" id="search-event-datepicker-to" class="js-basic-datepicker"
                                           placeholder="dd.mm.yyyy" data-bind="textInput: to_date, datepicker"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="search-count" class="pt-3" data-bind="text: resLength"></div>

            <!--            <div class="col-12 d-flex justify-content-start my-4 mb-md-0">-->
            <!--                <input type="checkbox" id="show_only_available" class="checkbox-fa"-->
            <!--                       data-bind="checked: show_only_available"/>-->
            <!--                <label class="choice text-purple text-label" for="show_only_available">-->
            <!--                    <i class="far fa-square unchecked-icon"></i>-->
            <!--                    <i class="far fa-check-square checked-icon"></i>-->
            <!--                    <trans>bookingfrontend:show_only_available</trans>-->
            <!--                </label>-->
            <!--            </div>-->

            <div id="search-result" class="pt-3">
                <div data-bind="foreach: { data: result().slice(0, result_shown()), as: 'event' }">
                    <event-info-card
                            params="{ event: event }"></event-info-card>
                </div>
            </div>

        </div>
    `
});



