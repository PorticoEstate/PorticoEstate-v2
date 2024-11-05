export function Capitalize(str: string | number | symbol): string {
    const s = str.toString();
    return s.charAt(0).toUpperCase() + s.substring(1);
}


export function DoubleDigitNumber(num: number): string {
    return num.toLocaleString('en-US', {
        minimumIntegerDigits: 2,
        useGrouping: false
    });
}

export function RenderDateTime(date: Date): string {
    const dayOfMonth = DoubleDigitNumber(date.getDate());
    const month = DoubleDigitNumber(date.getMonth() + 1);
    const year = date.getFullYear();
    const hours = DoubleDigitNumber(date.getHours());
    const minutes = DoubleDigitNumber(date.getMinutes());
    return `${dayOfMonth}.${month}.${year}, ${hours}:${minutes}`;
}
