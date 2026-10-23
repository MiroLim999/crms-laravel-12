function isMissingOptionalValue(value) {
    return value === null
        || value === undefined
        || (typeof value === 'string' && value.trim() === '');
}

export function positiveInteger(value) {
    if (isMissingOptionalValue(value)) return null;

    const number = Number(value);
    return Number.isInteger(number) && number > 0 ? number : null;
}

export function nonNegativeInteger(value) {
    if (isMissingOptionalValue(value)) return null;

    const number = Number(value);
    return Number.isInteger(number) && number >= 0 ? number : null;
}

/**
 * Optional camelCase metadata retained by FieldMarker.
 *
 * An order has no meaning without a group, so an order-only value is discarded.
 * A group without an order is retained so validation can still report that an
 * intended person group is incomplete.
 */
export function markerPersonMetadata(box) {
    const personGroup = positiveInteger(box?.personGroup);
    if (personGroup === null) return {};

    const personFieldOrder = nonNegativeInteger(box?.personFieldOrder);

    return {
        personGroup,
        ...(personFieldOrder === null ? {} : { personFieldOrder }),
    };
}

/**
 * Snake-case metadata sent with a Template Builder field.
 */
export function templatePersonPayload(box, customGrouping) {
    if (!customGrouping) {
        return {
            person_group: null,
            person_field_order: null,
        };
    }

    const personGroup = positiveInteger(box?.personGroup);

    return {
        person_group: personGroup,
        person_field_order: personGroup === null
            ? null
            : nonNegativeInteger(box?.personFieldOrder),
    };
}
