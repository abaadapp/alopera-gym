/**
 * A permission that opens nothing without another (every payroll step needs seeing salaries): ticking it ticks what it
 * needs, unticking the needed one unticks what needs it. Only boxes the editor may change (`mine`) move; the server
 * drops a step left without what it needs.
 */
export function togglePermission(list: string[], permission: string, requires: Record<string, string>, mine: (p: string) => boolean): string[] {
    if (list.includes(permission)) {
        const dependents = Object.keys(requires).filter((p) => requires[p] === permission && mine(p));
        return list.filter((p) => p !== permission && !dependents.includes(p));
    }
    const needed = requires[permission];
    return Array.from(new Set([...list, permission, ...(needed && mine(needed) ? [needed] : [])]));
}
