import { ComboSelect } from './combo-select';

export type AgentOption = { key: string; label: string };

export function AgentSelector({
    value,
    onChange,
    options,
    disabled,
}: {
    value: string;
    onChange: (agent: string) => void;
    options: AgentOption[];
    disabled: boolean;
}) {
    return (
        <ComboSelect
            value={value}
            options={options}
            onChange={onChange}
            disabled={disabled}
        />
    );
}
