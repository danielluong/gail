import { useRef, useState } from 'react';
import ChatController from '@/actions/App/Http/Controllers/ChatController';
import { apiFetch } from '@/lib/api';

type Status = 'idle' | 'recording' | 'transcribing';

export function useAudioRecorder(onTranscribed: (text: string) => void) {
    const [status, setStatus] = useState<Status>('idle');
    const recorderRef = useRef<MediaRecorder | null>(null);
    const chunksRef = useRef<Blob[]>([]);
    const streamRef = useRef<MediaStream | null>(null);

    async function start() {
        if (status !== 'idle') {
            return;
        }

        try {
            const stream =
                await navigator.mediaDevices.getUserMedia({ audio: true });
            streamRef.current = stream;

            const recorder = new MediaRecorder(stream);
            chunksRef.current = [];

            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    chunksRef.current.push(event.data);
                }
            };

            recorder.onstop = async () => {
                stream.getTracks().forEach((track) => track.stop());
                streamRef.current = null;

                const blob = new Blob(chunksRef.current, {
                    type: recorder.mimeType || 'audio/webm',
                });

                if (blob.size === 0) {
                    setStatus('idle');
                    return;
                }

                setStatus('transcribing');

                try {
                    const extension = (blob.type.split('/')[1] || 'webm')
                        .split(';')[0];
                    const formData = new FormData();
                    formData.append('audio', blob, `recording.${extension}`);

                    const response = await apiFetch(
                        ChatController.transcribe.url(),
                        {
                            method: 'POST',
                            headers: { Accept: 'application/json' },
                            body: formData,
                        },
                    );

                    if (response.ok) {
                        const data = await response.json();

                        if (typeof data.text === 'string' && data.text.trim()) {
                            onTranscribed(data.text.trim());
                        }
                    }
                } finally {
                    setStatus('idle');
                }
            };

            recorderRef.current = recorder;
            recorder.start();
            setStatus('recording');
        } catch {
            setStatus('idle');
        }
    }

    function stop() {
        if (recorderRef.current && status === 'recording') {
            recorderRef.current.stop();
            recorderRef.current = null;
        }
    }

    function cancel() {
        if (recorderRef.current) {
            recorderRef.current.ondataavailable = null;
            recorderRef.current.onstop = null;

            try {
                recorderRef.current.stop();
            } catch {
                // ignore
            }

            recorderRef.current = null;
        }

        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        chunksRef.current = [];
        setStatus('idle');
    }

    return { status, start, stop, cancel };
}
