/**
 * The kitchen's contact is said ONCE per kitchen on Min side (GH #1423): several locked servings of the same kitchen
 * used to repeat the same «Kontaktinformasjon for kjøkkenet» block on every card. Two contacts are the same kitchen
 * when everything the block shows is the same: the kitchen's own text and the building's name, email and phone (as
 * KitchenContact draws them, whitespace at the ends aside).
 */
export interface KitchenContactShape {
    info: string | null;
    building_name: string | null;
    building_email: string | null;
    building_phone: string | null;
}

const contactKey = (contact: KitchenContactShape): string =>
    JSON.stringify([contact.info, contact.building_name, contact.building_email, contact.building_phone].map(value => (value ?? '').trim()));

/** The distinct contacts in the order they first appear; a missing contact (null / undefined) is skipped. */
export const uniqueKitchenContacts = <T extends KitchenContactShape>(contacts: ReadonlyArray<T | null | undefined>): T[] => {
    const seen = new Set<string>();
    const unique: T[] = [];
    for (const contact of contacts) {
        if (!contact) continue;
        const key = contactKey(contact);
        if (seen.has(key)) continue;
        seen.add(key);
        unique.push(contact);
    }
    return unique;
};
