import { client } from './client';
import type { Params } from '../types';

export async function downloadExcel(resource: 'products' | 'stocks', params: Params) {
    const response = await client.get<Blob>(`api/v1/${resource}/export`, {
        params,
        responseType: 'blob',
    });
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${resource}.xlsx`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
