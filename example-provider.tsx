import { ZeroProvider } from '@rocicorp/zero/react';
import type { ZeroContext } from './generated';
import type { PropsWithChildren, ReactNode } from 'react';
import { useMemo } from 'react';
import { mutations } from './generated/mutations.generated';
import { schema } from './schema';

function AppZeroProvider({ children }: PropsWithChildren): ReactNode {
    const cacheURL = import.meta.env.VITE_ZERO_CACHE_URL;
    const mutateURL = import.meta.env.VITE_ZERO_MUTATE_URL;
    const queryURL = import.meta.env.VITE_ZERO_QUERY_URL;
    const context = useMemo<ZeroContext>(() => ({ user_id: "" }), []);

    return (
        <ZeroProvider {...{ cacheURL, context, mutateURL, mutators: mutations, queryURL, schema }} userID={userId}>
            {children}
        </ZeroProvider>
    );
}

export { AppZeroProvider };
