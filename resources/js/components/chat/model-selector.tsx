import { useEffect, useState } from 'react';
import ChatController from '@/actions/App/Http/Controllers/ChatController';
import { apiFetch } from '@/lib/api';
import { ComboSelect } from './combo-select';

export function ModelSelector({
    value,
    onChange,
    disabled,
}: {
    value: string;
    onChange: (model: string) => void;
    disabled: boolean;
}) {
    const [models, setModels] = useState<string[]>([]);

    useEffect(() => {
        apiFetch(ChatController.models.url(), {
            headers: { Accept: 'application/json' },
        })
            .then((res) => res.json())
            .then((data: string[]) => {
                setModels(data);

                if (data.length > 0 && !data.includes(value)) {
                    onChange(data[0]);
                }
            })
            .catch(() => {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const options = models.map((model) => ({
        key: model,
        label: model.replace(/:latest$/, ''),
    }));

    return (
        <ComboSelect
            value={value}
            options={options}
            onChange={onChange}
            disabled={disabled}
        />
    );
}
