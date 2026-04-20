import { useEffect, useState } from 'react';
import { getStored, setStored } from '@/lib/storage';

const MODEL_STORAGE_KEY = 'gail-model';
const TEMPERATURE_STORAGE_KEY = 'gail-temperature';
const AGENT_STORAGE_KEY = 'gail-agent';
const DEFAULT_MODEL = 'llama3.1:8b';
const DEFAULT_TEMPERATURE = 0.7;
const DEFAULT_AGENT = 'default';

/**
 * Persist and expose per-user chat preferences (model + sampling
 * temperature). Settings are hydrated from localStorage on first
 * render and written back whenever the user changes them.
 */
export function useChatSettings() {
    const [model, setModel] = useState(() =>
        getStored(MODEL_STORAGE_KEY, DEFAULT_MODEL),
    );
    const [temperature, setTemperature] = useState(() =>
        getStored(TEMPERATURE_STORAGE_KEY, DEFAULT_TEMPERATURE),
    );
    const [agent, setAgent] = useState(() =>
        getStored(AGENT_STORAGE_KEY, DEFAULT_AGENT),
    );

    useEffect(() => {
        setStored(MODEL_STORAGE_KEY, model);
    }, [model]);

    useEffect(() => {
        setStored(TEMPERATURE_STORAGE_KEY, temperature);
    }, [temperature]);

    useEffect(() => {
        setStored(AGENT_STORAGE_KEY, agent);
    }, [agent]);

    return { model, setModel, temperature, setTemperature, agent, setAgent };
}
