import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Layout } from './components/Layout';
import { DashboardPage } from './pages/DashboardPage';
import { DataPage } from './pages/DataPage';
import { ExplorePage } from './pages/ExplorePage';
import { KeywordPage } from './pages/KeywordPage';
import { CandidatesPage } from './pages/CandidatesPage';
import { NichesPage } from './pages/NichesPage';
import { NichePage } from './pages/NichePage';
import { HypothesesPage, HypothesisPage } from './pages/HypothesesPage';
import { SettingsPage } from './pages/SettingsPage';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 15_000, refetchOnWindowFocus: false },
  },
});

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Layout>
          <Routes>
            <Route path="/" element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard" element={<DashboardPage />} />
            <Route path="/data" element={<DataPage />} />
            <Route path="/explore" element={<ExplorePage />} />
            <Route path="/keywords/:id" element={<KeywordPage />} />
            <Route path="/candidates" element={<CandidatesPage />} />
            <Route path="/niches" element={<NichesPage />} />
            <Route path="/niches/:id" element={<NichePage />} />
            <Route path="/hypotheses" element={<HypothesesPage />} />
            <Route path="/hypotheses/:id" element={<HypothesisPage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>
        </Layout>
      </BrowserRouter>
    </QueryClientProvider>
  );
}
